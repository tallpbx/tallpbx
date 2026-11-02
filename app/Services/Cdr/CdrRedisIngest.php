<?php

declare(strict_types=1);

namespace App\Services\Cdr;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\CallDetailRecords\Models\Cdr;
use Throwable;

/**
 * Buffers raw call detail records in the isolated Redis stream and digests
 * them into MariaDB.
 *
 * Ingestion is the fast half: the HTTP endpoint only appends. Digestion is
 * the durable half: entries are parsed, committed to MariaDB in one
 * transaction per batch, and deleted from the stream only after that commit
 * (or after a successful quarantine copy) — so a crash at any point replays
 * entries instead of losing them.
 */
class CdrRedisIngest
{
    /** Stream holding raw payloads between the HTTP hand-off and the worker. */
    public const STREAM_KEY = 'pbx:cdr:stream';

    /** Cache lock serializing digest cycles across workers. */
    public const LOCK_NAME = 'pbx:cdr:digest-lock';

    /**
     * Create the buffer with its parser.
     */
    public function __construct(private readonly CdrPayloadParser $parser) {}

    /**
     * Append one raw payload and return its stream entry id.
     *
     * @param  string  $rawJson  The untouched document mod_json_cdr posted
     * @return string Stream entry id, e.g. "1698847200000-0"
     */
    public function ingest(string $rawJson): string
    {
        /** @var string $id */
        $id = $this->connection()->xadd(self::STREAM_KEY, '*', ['data' => $rawJson]);

        return $id;
    }

    /**
     * Digest up to the given number of stream entries into MariaDB.
     *
     * Malformed or unattributable payloads are quarantined to disk and only
     * then removed from the stream; when the quarantine copy fails the entry
     * stays put for the next cycle. Returns the number of entries removed.
     */
    public function digest(int $chunkSize = 100): int
    {
        // A second worker would race this cycle; the upsert would stay
        // idempotent, but one digester keeps the quarantine and journal
        // output clean.
        $lock = Cache::lock(self::LOCK_NAME, 60);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $entries = $this->connection()->xrange(self::STREAM_KEY, '-', '+', $chunkSize);

            if ($entries === []) {
                return 0;
            }

            $batch = [];
            $processedIds = [];

            foreach ($entries as $entryId => $fields) {
                $raw = $fields['data'] ?? '';
                $row = $this->parser->parse($raw);

                if ($row === null) {
                    // Keep the entry when the quarantine copy failed.
                    if ($this->parser->quarantine((string) $entryId, $raw)) {
                        $processedIds[] = (string) $entryId;
                    }

                    continue;
                }

                $batch[] = $row;
                $processedIds[] = (string) $entryId;
            }

            if ($batch !== []) {
                DB::transaction(function () use ($batch): void {
                    Cdr::upsert(
                        $batch,
                        ['call_uuid', 'leg'],
                        [
                            'tenant_id', 'b_leg_uuid', 'caller_id', 'caller_id_name', 'destination',
                            'duration', 'billsec', 'hangup_cause', 'direction', 'record_path',
                            'start_stamp', 'answer_stamp', 'end_stamp', 'json_data', 'updated_at',
                        ],
                    );
                });
            }

            // Deleting only after the commit is what makes a crash between
            // commit and XDEL a replay instead of data loss.
            if ($processedIds !== []) {
                $this->connection()->xdel(self::STREAM_KEY, $processedIds);
            }

            return count($processedIds);
        } catch (Throwable $exception) {
            Log::error('CDR digest cycle failed; stream entries were kept.', [
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Count entries still waiting for the worker.
     *
     * Used by the worker's backlog warning to surface an unbounded stream
     * before a reboot would put that backlog at risk.
     */
    public function getPendingCount(): int
    {
        /** @var int $count */
        $count = $this->connection()->xlen(self::STREAM_KEY);

        return (int) $count;
    }

    /**
     * Resolve the isolated stream connection.
     *
     * Protected so tests can drive the digest cycle against an in-memory
     * stream instead of a live Redis.
     *
     * @return mixed PhpRedis connection with xrange/xlen/xdel/xadd
     */
    protected function connection(): mixed
    {
        return Redis::connection('cdr');
    }
}
