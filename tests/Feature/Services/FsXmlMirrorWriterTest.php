<?php

declare(strict_types=1);

use App\Services\Xml\FsXmlMirrorKeys;
use App\Services\Xml\FsXmlMirrorWriter;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * The mirror writer is best-effort by design: a Redis outage may cost cache
 * hits, but it must never break the panel save that triggered the write.
 * These tests cover the key contract, the TTL, the feature flag, and every
 * failure path.
 *
 * Writes go through an isolated connection that reuses the real lua_proxy
 * settings plus a random prefix, so parallel workers sharing the test Redis
 * database cannot collide on the fixed pbx:fsxml keys. The unprefixed
 * contract of the real connection is asserted separately below.
 */

/**
 * The isolated, prefixed connection used by every write in this file.
 */
function luaMirrorTestConnection(): Connection
{
    return Redis::connection(config('freeswitch.lua_proxy.connection'));
}

beforeEach(function (): void {
    $connection = 'lua_proxy_test';

    config([
        'freeswitch.lua_proxy.enabled' => true,
        'freeswitch.lua_proxy.connection' => $connection,
        'database.redis.'.$connection => array_merge(config('database.redis.lua_proxy'), [
            'prefix' => 'tallpbx-test-'.Str::lower(Str::random(8)).'-',
        ]),
        'freeswitch.xml_handler.directory_cache_ttl' => 60,
    ]);
});

afterEach(function (): void {
    // The failure-path test swaps the Redis facade for a throwing mock on
    // purpose, so cleanup must not assume a working connection.
    try {
        luaMirrorTestConnection()->del(
            FsXmlMirrorKeys::tenantLookup('a.com', '1001'),
            FsXmlMirrorKeys::directory(7, 'domain', 'a.com', '1001', 3),
            FsXmlMirrorKeys::version(7),
            FsXmlMirrorKeys::globalVersion(),
        );
    } catch (Throwable) {
        // Nothing was written by the failed-write test either.
    }
});

test('uses dedicated lua_proxy connection with empty redis prefix', function () {
    $conn = Redis::connection('lua_proxy');

    expect($conn->client()->getOption(\Redis::OPT_PREFIX))->toBeNull();
});

test('writes directory payload with ttl and tenant lookup via raw redis without prefix', function () {
    app(FsXmlMirrorWriter::class)->writeTenantLookup('a.com', '1001', 7);
    app(FsXmlMirrorWriter::class)->writeDirectory(7, 'domain', 'a.com', '1001', 3, '<document type="freeswitch/xml"/>');

    $conn = luaMirrorTestConnection();
    expect($conn->get(FsXmlMirrorKeys::tenantLookup('a.com', '1001')))->toBe('7')
        ->and($conn->ttl(FsXmlMirrorKeys::directory(7, 'domain', 'a.com', '1001', 3)))->toBeGreaterThan(0);
});

test('writes version keys without an expiry', function () {
    app(FsXmlMirrorWriter::class)->writeVersion(7, 4);
    app(FsXmlMirrorWriter::class)->writeGlobalVersion(9);

    $conn = luaMirrorTestConnection();
    expect($conn->get(FsXmlMirrorKeys::version(7)))->toBe('4')
        ->and($conn->ttl(FsXmlMirrorKeys::version(7)))->toBe(-1)
        ->and($conn->get(FsXmlMirrorKeys::globalVersion()))->toBe('9');
});

test('writes nothing when the feature flag is disabled', function () {
    config(['freeswitch.lua_proxy.enabled' => false]);

    app(FsXmlMirrorWriter::class)->writeTenantLookup('a.com', '1001', 7);
    app(FsXmlMirrorWriter::class)->writeVersion(7, 4);

    $conn = luaMirrorTestConnection();
    expect($conn->exists(FsXmlMirrorKeys::tenantLookup('a.com', '1001')))->toBe(0)
        ->and($conn->exists(FsXmlMirrorKeys::version(7)))->toBe(0);
});

test('writes nothing when directory caching is disabled', function () {
    config(['freeswitch.xml_handler.directory_cache_ttl' => 0]);

    app(FsXmlMirrorWriter::class)->writeTenantLookup('a.com', '1001', 7);
    app(FsXmlMirrorWriter::class)->writeDirectory(7, 'domain', 'a.com', '1001', 3, '<document type="freeswitch/xml"/>');

    $conn = luaMirrorTestConnection();
    expect($conn->exists(FsXmlMirrorKeys::tenantLookup('a.com', '1001')))->toBe(0)
        ->and($conn->exists(FsXmlMirrorKeys::directory(7, 'domain', 'a.com', '1001', 3)))->toBe(0);
});

test('skips documents above the size cap so large tenants fall through to php', function () {
    $oversized = '<document>'.str_repeat('x', FsXmlMirrorWriter::MAX_PAYLOAD_BYTES).'</document>';

    app(FsXmlMirrorWriter::class)->writeDirectory(7, 'domain', 'a.com', '1001', 3, $oversized);

    expect(luaMirrorTestConnection()->exists(FsXmlMirrorKeys::directory(7, 'domain', 'a.com', '1001', 3)))->toBe(0);
});

test('does not throw when the redis connection fails', function () {
    Redis::shouldReceive('connection')->andThrow(new RedisException('down'));

    app(FsXmlMirrorWriter::class)->writeVersion(7, 4);
    app(FsXmlMirrorWriter::class)->writeTenantLookup('a.com', '1001', 7);

    // Reaching this assertion proves the three calls above swallowed the outage.
    expect(app(FsXmlMirrorWriter::class)->readVersion(7))->toBeNull();
});

test('seeds a version key only when it is missing', function () {
    $writer = app(FsXmlMirrorWriter::class);

    $writer->writeVersionIfMissing(7, 4);
    expect(luaMirrorTestConnection()->get(FsXmlMirrorKeys::version(7)))->toBe('4');

    // A reader that sampled an older revision must never roll the key back.
    $writer->writeVersionIfMissing(7, 9);
    expect(luaMirrorTestConnection()->get(FsXmlMirrorKeys::version(7)))->toBe('4');
});

test('reads back a literal or mirrored version for the monotonic bump guard', function () {
    $writer = app(FsXmlMirrorWriter::class);

    expect($writer->readVersion(7))->toBeNull()
        ->and($writer->readGlobalVersion())->toBeNull();

    $writer->writeVersion(7, 4);
    $writer->writeGlobalVersion(9);

    expect($writer->readVersion(7))->toBe(4)
        ->and($writer->readGlobalVersion())->toBe(9);
});
