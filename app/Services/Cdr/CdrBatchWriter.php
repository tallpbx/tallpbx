<?php

declare(strict_types=1);

namespace App\Services\Cdr;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\CallDetailRecords\Models\Cdr;

/**
 * Commits parsed call records to MariaDB in one transaction.
 *
 * Shared by the stream digest and the spool drainer so both paths keep the
 * exact same upsert semantics: one row per (call_uuid, leg), so a replayed
 * record updates instead of duplicating.
 *
 * A deterministic rejection — an integrity (23000) or data (22xxx) exception —
 * isolates the offending row instead of rolling the whole batch back; every
 * other failure (an outage, a lost connection) is re-thrown so callers keep
 * every source record for the next attempt.
 */
class CdrBatchWriter
{
    /**
     * Columns refreshed when a row already exists for (call_uuid, leg).
     *
     * `tenant_id` is deliberately absent: ownership is assigned once, at first
     * insert. A delayed or replayed payload must never move an existing record
     * into another tenant's history.
     *
     * @var array<int, string>
     */
    private const UPDATE_COLUMNS = [
        'b_leg_uuid', 'caller_id', 'caller_id_name', 'destination',
        'duration', 'billsec', 'hangup_cause', 'direction', 'record_path',
        'start_stamp', 'answer_stamp', 'end_stamp', 'json_data', 'updated_at',
    ];

    /**
     * Upsert every parsed row, returning the ids the database rejected.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string> Ids that were not written
     */
    public function write(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        try {
            DB::transaction(function () use ($rows): void {
                Cdr::upsert($rows, ['call_uuid', 'leg'], self::UPDATE_COLUMNS);
            });

            return [];
        } catch (QueryException $exception) {
            if (! $this->isDeterministicRejection($exception)) {
                // A transient failure keeps the whole batch for a retry.
                throw $exception;
            }
        }

        return $this->writeIndividually($rows);
    }

    /**
     * Retry each row in its own transaction so one bad record cannot poison
     * the others.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function writeIndividually(array $rows): array
    {
        $failed = [];

        foreach ($rows as $row) {
            try {
                DB::transaction(function () use ($row): void {
                    Cdr::upsert([$row], ['call_uuid', 'leg'], self::UPDATE_COLUMNS);
                });
            } catch (QueryException $exception) {
                if (! $this->isDeterministicRejection($exception)) {
                    throw $exception;
                }

                $failed[] = (string) ($row['id'] ?? '');
            }
        }

        return $failed;
    }

    /**
     * Report whether the database rejected this row on its own merits.
     *
     * SQLSTATE 23000 covers integrity constraints; a class-22 code is a data
     * exception (bad value, truncation). Anything else — a timeout, a lost
     * connection — is transient and must keep the batch intact.
     */
    private function isDeterministicRejection(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        return $code === '23000' || str_starts_with($code, '22');
    }
}
