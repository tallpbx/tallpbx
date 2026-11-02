<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Cdr\CdrBatchWriter;
use App\Services\Cdr\CdrPayloadParser;
use App\Services\Cdr\CdrRedisIngest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
 * The digest cycle is the integrity core of the pipeline: rows are committed
 * to MariaDB in one transaction, and stream entries are deleted only after
 * that commit or a successful quarantine copy — never before.
 */

/**
 * In-memory stand-in for the Redis stream commands digest() uses.
 */
class FakeCdrStream
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
     * Mirror XDEL.
     *
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
class TestableCdrIngest extends CdrRedisIngest
{
    /**
     * Wire the digest cycle to the in-memory stream.
     */
    public function __construct(CdrPayloadParser $parser, public FakeCdrStream $stream)
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

/**
 * Build a digest driver with a throwaway quarantine directory.
 */
function cdrDigestDriver(?CdrPayloadParser $parser = null): TestableCdrIngest
{
    $parser ??= new CdrPayloadParser(sys_get_temp_dir().'/cdr-quarantine-'.uniqid());

    return new TestableCdrIngest($parser, new FakeCdrStream);
}

/**
 * One captured payload with a resolvable tenant domain overlaid.
 */
function cdrDigestPayload(string $leg): string
{
    return cdrFixture($leg, ['domain_name' => 'digest.example']);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    TenantDomain::factory()->create(['tenant_id' => $this->tenant->id, 'domain' => 'digest.example']);
});

it('commits both legs in one transaction and deletes entries only afterwards', function () {
    $driver = cdrDigestDriver();
    $driver->stream->add('1-1', cdrDigestPayload('a'));
    $driver->stream->add('1-2', cdrDigestPayload('b'));
    $driver->stream->add('1-3', 'not json at all');

    $processed = $driver->digest(100);

    expect($processed)->toBe(3)
        ->and($driver->stream->entries)->toBeEmpty()
        ->and($driver->stream->deletedIds)->toBe(['1-1', '1-2', '1-3']);

    $rows = DB::table('cdrs')->orderBy('leg')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('leg')->all())->toBe(['a', 'b'])
        ->and($rows->pluck('call_uuid')->all())->toBe([
            '8682bd04-2fbd-4dcb-a759-68973a83bae5',
            '97a4bd05-3ad2-474e-832a-7bdaae999bb0',
        ])
        ->and($rows->firstWhere('leg', 'a')->b_leg_uuid)->toBe('97a4bd05-3ad2-474e-832a-7bdaae999bb0');

    // The malformed payload was quarantined, never dropped or inserted.
    expect(glob(sys_get_temp_dir().'/cdr-quarantine-*/*.json'))->not->toBeEmpty();
});

it('updates instead of duplicating when the same record is digested again', function () {
    $driver = cdrDigestDriver();
    $driver->stream->add('1-1', cdrDigestPayload('a'));
    $driver->digest(100);

    // A crash between commit and XDEL replays the entry under the same id.
    $driver->stream->add('1-1', cdrFixture('a', [
        'domain_name' => 'digest.example',
        'duration' => '42',
        'billsec' => '42',
    ]));
    $driver->digest(100);

    $rows = DB::table('cdrs')->where('leg', 'a')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->duration)->toBe(42)
        ->and($rows->first()->billsec)->toBe(42);
});

it('keeps every entry when the MariaDB transaction fails', function () {
    // A row that cannot be inserted aborts the whole batch transaction.
    $parser = new class('') extends CdrPayloadParser
    {
        /**
         * Return a row that cannot be inserted, to abort the batch transaction.
         */
        public function parse(string $rawJson): ?array
        {
            return [
                'id' => null,
                'tenant_id' => 1,
                'call_uuid' => 'not-insertable',
                'leg' => 'a',
                'json_data' => $rawJson,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }
    };

    $driver = cdrDigestDriver($parser);
    $driver->stream->add('1-1', cdrDigestPayload('a'));

    expect(fn () => $driver->digest(100))->toThrow(QueryException::class);

    // Nothing was committed, so nothing may be deleted either.
    expect($driver->stream->entries)->toHaveCount(1)
        ->and($driver->stream->deletedIds)->toBe([])
        ->and(DB::table('cdrs')->count())->toBe(0);
});

it('keeps an unparseable entry when the quarantine copy fails', function () {
    $fileAsDir = sys_get_temp_dir().'/cdr-blocked-'.uniqid();
    file_put_contents($fileAsDir, 'not a directory');

    $driver = cdrDigestDriver(new CdrPayloadParser($fileAsDir));
    $driver->stream->add('1-1', 'not json at all');

    expect($driver->digest(100))->toBe(0)
        ->and($driver->stream->entries)->toHaveCount(1)
        ->and($driver->stream->deletedIds)->toBe([]);
});

it('skips the cycle when another worker holds the digest lock', function () {
    $driver = cdrDigestDriver();
    $driver->stream->add('1-1', cdrDigestPayload('a'));

    $lock = Cache::lock(CdrRedisIngest::LOCK_NAME, 30);
    expect($lock->get())->toBeTrue();

    try {
        expect($driver->digest(100))->toBe(0)
            ->and($driver->stream->entries)->toHaveCount(1)
            ->and(DB::table('cdrs')->count())->toBe(0);
    } finally {
        $lock->release();
    }
});
