<?php

declare(strict_types=1);

use App\Services\Xml\FsXmlMirrorKeys;
use App\Support\RoutingCacheVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/*
 * A routing revision is what makes a mirrored directory document stale. Two
 * rules keep it safe: every bump is copied into the Lua namespace, and a bump
 * after a cache flush can never move backwards into payload keys that still
 * exist in Redis DB 0.
 */

beforeEach(function (): void {
    config(['freeswitch.lua_proxy.enabled' => true, 'freeswitch.lua_proxy.connection' => 'lua_proxy']);
    Cache::forget(RoutingCacheVersion::key(42));
    Cache::forget(RoutingCacheVersion::globalKey());

    try {
        Redis::connection('lua_proxy')->del(FsXmlMirrorKeys::version(42), FsXmlMirrorKeys::globalVersion());
    } catch (Throwable) {
        // A previous test may have replaced the Redis facade on purpose.
    }
});

afterEach(function (): void {
    try {
        Redis::connection('lua_proxy')->del(FsXmlMirrorKeys::version(42), FsXmlMirrorKeys::globalVersion());
    } catch (Throwable) {
        // The failure-path test swaps the Redis facade for a throwing mock.
    }
});

test('mirrors the tenant revision into the lua namespace on bump', function () {
    RoutingCacheVersion::bump(42);

    expect(Redis::connection('lua_proxy')->get(FsXmlMirrorKeys::version(42)))
        ->toBe((string) RoutingCacheVersion::get(42));
});

test('mirrors the global revision on global bump', function () {
    RoutingCacheVersion::bumpGlobal();

    expect(Redis::connection('lua_proxy')->get(FsXmlMirrorKeys::globalVersion()))
        ->toBe((string) RoutingCacheVersion::getGlobal());
});

test('advances monotonically when cache store was cleared but mirror key exists', function () {
    // Simulate a pre-existing mirror key at version 5 in DB 0.
    Redis::connection('lua_proxy')->set(FsXmlMirrorKeys::version(42), '5');
    // Simulate the DB 1 cache clear that tpbx optimize:clear performs.
    Cache::forget(RoutingCacheVersion::key(42));

    RoutingCacheVersion::bump(42);

    // The version must advance past the orphaned payload keys, not restart at 1.
    expect((int) RoutingCacheVersion::get(42))->toBeGreaterThanOrEqual(6)
        ->and((int) Redis::connection('lua_proxy')->get(FsXmlMirrorKeys::version(42)))->toBeGreaterThanOrEqual(6);
});

test('a bump still succeeds when the mirror writer throws', function () {
    Redis::shouldReceive('connection')->andThrow(new RedisException('down'));

    RoutingCacheVersion::bump(42);

    // Reaching this line proves the bump did not propagate the Redis outage.
    expect(RoutingCacheVersion::get(42))->toBeGreaterThan(0);
});
