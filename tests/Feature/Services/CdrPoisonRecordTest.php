<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Cdr\CdrBatchWriter;
use App\Services\Cdr\CdrPayloadParser;
use App\Services\Cdr\CdrRedisIngest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\CallDetailRecords\Models\Cdr;

/**
 * In-memory stand-in for the Redis stream commands digest() uses.
 */
final class PoisonFakeStream
{
    /** @var array<string, array<string, string>> */
    public array $entries = [];

    /** @var array<int, string> */
    public array $deletedIds = [];

    /**
     * Add one payload under a stream entry id.
     */
    public function add(string $id, string $payload): void
    {
        $this->entries[$id] = ['data' => $payload];
    }

    /**
     * Mirror XRANGE with a count cap.
     */
    public function xrange(string $key, string $from, string $to, int $count): array
    {
        return array_slice($this->entries, 0, $count, true);
    }

    /**
     * @param  array<int, string>  $ids
     */
    public function xdel(string $key, array $ids): int
    {
        foreach ($ids as $id) {
            $this->deletedIds[] = $id;
            unset($this->entries[$id]);
        }

        return count($ids);
    }

    /**
     * Mirror XLEN.
     */
    public function xlen(string $key): int
    {
        return count($this->entries);
    }
}

/**
 * Digest driver pointed at the in-memory stream instead of Redis.
 */
final class PoisonTestableIngest extends CdrRedisIngest
{
    /**
     * Wire the digest cycle to the in-memory stream.
     */
    public function __construct(CdrPayloadParser $parser, public PoisonFakeStream $stream)
    {
        parent::__construct($parser, new CdrBatchWriter);
    }

    /**
     * Serve the fake stream to the digest cycle.
     */
    protected function connection(): mixed
    {
        return $this->stream;
    }
}

/*
 * One permanently invalid record must never block or roll back the records
 * around it. Deterministic database rejections (integrity or data exceptions)
 * isolate the offending row into quarantine; transient outages still keep the
 * whole batch so it is retried.
 */

/**
 * Build one valid CDR row.
 *
 * @return array<string, mixed>
 */
function cdrPoisonRow(string $callUuid, int $tenantId, ?string $id = null): array
{
    return [
        'id' => $id ?? (string) Str::orderedUuid(),
        'tenant_id' => $tenantId,
        'call_uuid' => $callUuid,
        'b_leg_uuid' => null,
        'leg' => 'a',
        'caller_id' => '1001',
        'caller_id_name' => 'Test',
        'destination' => '2001',
        'duration' => 10,
        'billsec' => 10,
        'hangup_cause' => 'NORMAL_CLEARING',
        'direction' => 'outbound',
        'record_path' => null,
        'start_stamp' => now(),
        'answer_stamp' => now(),
        'end_stamp' => now(),
        'json_data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
});

it('commits the valid rows and reports the one the database rejects', function (): void {
    // An existing row already owns this primary key, so a second insert with
    // the same id but a different (call_uuid, leg) is an integrity violation.
    $existing = Cdr::factory()->create(['tenant_id' => $this->tenant->id, 'call_uuid' => 'occupied']);

    $rows = [
        cdrPoisonRow('u1', $this->tenant->id),
        cdrPoisonRow('u2', $this->tenant->id, $existing->id),
        cdrPoisonRow('u3', $this->tenant->id),
    ];

    $failed = app(CdrBatchWriter::class)->write($rows);

    expect($failed)->toBe([$rows[1]['id']])
        ->and(Cdr::withoutGlobalScope('tenant')->whereIn('call_uuid', ['u1', 'u3'])->count())->toBe(2)
        ->and(Cdr::withoutGlobalScope('tenant')->where('call_uuid', 'u2')->exists())->toBeFalse();
});

it('quarantines and removes a poison entry while later entries keep flowing', function (): void {
    $existing = Cdr::factory()->create(['tenant_id' => $this->tenant->id, 'call_uuid' => 'occupied']);
    $quarantine = sys_get_temp_dir().'/cdr-poison-'.uniqid();

    $parser = new class($quarantine, $existing->id) extends CdrPayloadParser
    {
        /**
         * Point the parser at the quarantine dir and remember the colliding id.
         */
        public function __construct(string $dir, private readonly string $collidingId)
        {
            parent::__construct($dir);
        }

        /**
         * Return controlled rows: one violates the primary key, one is valid.
         */
        public function parse(string $rawJson): ?array
        {
            $tenantId = Tenant::query()->value('id');

            return str_contains($rawJson, 'poison')
                ? cdrPoisonRow('poisoned', (int) $tenantId, $this->collidingId)
                : cdrPoisonRow('healthy', (int) $tenantId);
        }
    };

    // FakeCdrStream and TestableCdrIngest are declared by the digest test file.
    $stream = new PoisonFakeStream;
    $stream->add('1-1', '{"marker":"poison"}');
    $stream->add('1-2', '{"marker":"healthy"}');

    $ingest = new PoisonTestableIngest($parser, $stream);

    expect($ingest->digest(100))->toBe(2)
        ->and($stream->entries)->toBeEmpty()
        ->and($stream->deletedIds)->toEqualCanonicalizing(['1-1', '1-2'])
        ->and(glob($quarantine.'/*.json'))->toHaveCount(1)
        ->and(Cdr::withoutGlobalScope('tenant')->where('call_uuid', 'healthy')->exists())->toBeTrue()
        ->and($ingest->getPendingCount())->toBe(0);
});

it('quarantines a rejected spool file instead of deleting it', function (): void {
    $spool = sys_get_temp_dir().'/cdr-spool-poison-'.uniqid();
    $quarantine = sys_get_temp_dir().'/cdr-spool-quarantine-'.uniqid();
    File::ensureDirectoryExists($spool);

    // Two capturable payloads; the first row is reported as rejected by the
    // writer, so its file must be quarantined and the other drained.
    file_put_contents($spool.'/a-poison.cdr.json', cdrFixture('a', [
        'tenant_id' => (string) $this->tenant->id,
    ]));
    file_put_contents($spool.'/b-healthy.cdr.json', cdrFixture('b', [
        'tenant_id' => (string) $this->tenant->id,
    ]));
    touch($spool.'/a-poison.cdr.json', time() - 60);
    touch($spool.'/b-healthy.cdr.json', time() - 60);

    $this->app->instance(CdrBatchWriter::class, new class extends CdrBatchWriter
    {
        /**
         * Commit every row except the first, which is reported as rejected.
         *
         * @param  array<int, array<string, mixed>>  $rows
         * @return array<int, string>
         */
        public function write(array $rows): array
        {
            if ($rows === []) {
                return [];
            }

            $rejected = array_shift($rows);
            parent::write($rows);

            return [(string) ($rejected['id'] ?? '')];
        }
    });

    try {
        $this->artisan('cdr:drain-spool', [
            '--spool-dir' => $spool,
            '--quarantine-dir' => $quarantine,
            '--grace' => 0,
        ])->assertSuccessful();

        expect(glob($spool.'/*.json'))->toBe([])
            ->and(glob($quarantine.'/*.json'))->toHaveCount(1)
            ->and(Cdr::withoutGlobalScope('tenant')->count())->toBe(1);
    } finally {
        File::deleteDirectory($spool);
        File::deleteDirectory($quarantine);
    }
});

it('keeps the original owner when a record is replayed', function (): void {
    $other = Tenant::factory()->create();

    Cdr::factory()->create([
        'tenant_id' => $this->tenant->id,
        'call_uuid' => 'u9',
        'leg' => 'a',
    ]);

    app(CdrBatchWriter::class)->write([cdrPoisonRow('u9', $other->id)]);

    expect(Cdr::withoutGlobalScope('tenant')->where('call_uuid', 'u9')->value('tenant_id'))->toBe($this->tenant->id);
});
