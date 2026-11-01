<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use App\Services\Xml\DialplanXmlBuilder;
use App\Services\Xml\FsXmlMirrorKeys;
use App\Support\RoutingCacheVersion;
use Illuminate\Support\Facades\Redis;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;

/*
 * Dialplan mirroring is the half that matters most: every call leg produces a
 * dialplan lookup, while directory lookups happen only per registration. A
 * stale dialplan does not merely show wrong data, it routes a live call the
 * wrong way, so these tests pin the key shape, both revisions it carries, and
 * the refusal to publish a document rendered for an older revision.
 */

beforeEach(function (): void {
    config([
        'freeswitch.lua_proxy.enabled' => true,
        'freeswitch.lua_proxy.connection' => 'lua_proxy',
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
        // Contributor fragments are cached separately; switching that off keeps
        // each case here about the final document only.
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 0,
    ]);

    $this->mirrorKeys = [];

    // Unique tenant IDs keep parallel workers off each other's raw Redis keys:
    // every worker's SQLite database starts at tenant 1.
    $this->makeDialplanFixture = function (): array {
        $tenant = Tenant::factory()->create(['id' => random_int(1_000_000, 2_000_000_000)]);
        app(TenantManager::class)->setTenantId((string) $tenant->id);

        return [(int) $tenant->id, 'tenant_'.$tenant->id.'_internal', '2000'];
    };
});

afterEach(function (): void {
    app(TenantManager::class)->clear();

    try {
        Redis::connection('lua_proxy')->del(...$this->mirrorKeys);
    } catch (Throwable) {
        // Cleanup must not mask a test failure caused by the Redis mock.
    }
});

test('mirrors the rendered dialplan under a version and epoch key', function () {
    [$tenantId, $context, $destination] = ($this->makeDialplanFixture)();

    $xml = app(DialplanXmlBuilder::class)->buildCachedDialplanXml($context, $destination, '2001');

    $version = (int) RoutingCacheVersion::get($tenantId);
    $epoch = (int) RoutingCacheVersion::getGlobal();
    $key = FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, $version, $epoch);

    $this->mirrorKeys[] = $key;
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();

    $conn = Redis::connection('lua_proxy');

    expect($conn->get($key))->toBe($xml)
        ->and($xml)->toContain('name="dialplan"')
        ->and($conn->ttl($key))->toBeGreaterThan(0);
});

test('seeds the tenant revision and global epoch when redis is cold', function () {
    [$tenantId, $context, $destination] = ($this->makeDialplanFixture)();

    // A fresh install may see a dialplan lookup before any directory lookup,
    // so the dialplan path has to seed both revisions the Lua handler reads to
    // build its key at all.
    Redis::connection('lua_proxy')->del(FsXmlMirrorKeys::version($tenantId), FsXmlMirrorKeys::globalVersion());

    app(DialplanXmlBuilder::class)->buildCachedDialplanXml($context, $destination, '2001');

    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();
    $this->mirrorKeys[] = FsXmlMirrorKeys::dialplan(
        $tenantId,
        $context,
        $destination,
        (int) RoutingCacheVersion::get($tenantId),
        (int) RoutingCacheVersion::getGlobal(),
    );

    $conn = Redis::connection('lua_proxy');

    expect($conn->get(FsXmlMirrorKeys::version($tenantId)))->toBe((string) RoutingCacheVersion::get($tenantId))
        ->and($conn->get(FsXmlMirrorKeys::globalVersion()))->toBe((string) RoutingCacheVersion::getGlobal());
});

test('does not mirror when dialplan caching is disabled', function () {
    config(['freeswitch.xml_handler.dialplan_cache_ttl' => 0]);

    [$tenantId, $context, $destination] = ($this->makeDialplanFixture)();

    app(DialplanXmlBuilder::class)->buildCachedDialplanXml($context, $destination, '2001');

    $conn = Redis::connection('lua_proxy');
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();

    expect($conn->exists(FsXmlMirrorKeys::dialplan(
        $tenantId,
        $context,
        $destination,
        (int) RoutingCacheVersion::get($tenantId),
        (int) RoutingCacheVersion::getGlobal(),
    )))->toBe(0);
});

test('does not mirror when the lua proxy is disabled', function () {
    config(['freeswitch.lua_proxy.enabled' => false]);

    [$tenantId, $context, $destination] = ($this->makeDialplanFixture)();

    app(DialplanXmlBuilder::class)->buildCachedDialplanXml($context, $destination, '2001');

    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();

    $conn = Redis::connection('lua_proxy');

    expect($conn->exists(FsXmlMirrorKeys::dialplan(
        $tenantId,
        $context,
        $destination,
        (int) RoutingCacheVersion::get($tenantId),
        (int) RoutingCacheVersion::getGlobal(),
    )))->toBe(0)
        ->and($conn->exists(FsXmlMirrorKeys::version($tenantId)))->toBe(0)
        ->and($conn->exists(FsXmlMirrorKeys::globalVersion()))->toBe(0);
});

test('skips publishing when the revision moves while the dialplan renders', function () {
    [$tenantId, $context, $destination] = ($this->makeDialplanFixture)();

    // translate() runs unconditionally inside the render, so bumping there
    // simulates an administrator saving a routing change mid-request.
    $this->mock(NumberTranslationServiceInterface::class, function ($mock) use ($tenantId): void {
        $mock->shouldReceive('translate')
            ->andReturnUsing(function (string $candidate) use ($tenantId): string {
                RoutingCacheVersion::bump($tenantId);

                return $candidate;
            });
    });

    $before = (int) RoutingCacheVersion::get($tenantId);
    $epoch = (int) RoutingCacheVersion::getGlobal();

    app(DialplanXmlBuilder::class)->buildCachedDialplanXml($context, $destination, '2001');

    $after = (int) RoutingCacheVersion::get($tenantId);

    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, $before, $epoch);
    $this->mirrorKeys[] = FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, $after, $epoch);

    $conn = Redis::connection('lua_proxy');

    // The document describes the revision it started under. Publishing it
    // under either revision would let Lua route a live call with rules that
    // were already superseded.
    expect($after)->toBe($before + 1)
        ->and($conn->exists(FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, $before, $epoch)))->toBe(0)
        ->and($conn->exists(FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, $after, $epoch)))->toBe(0);
});

test('publishes under the mirror revision after the cache store is flushed', function () {
    [$tenantId, $context, $destination] = ($this->makeDialplanFixture)();

    // optimize:clear empties the PHP cache store while the mirror keeps the
    // last revision and its payload keys. Publishing under the store's
    // restarted revision would make every Lua lookup miss indefinitely.
    Redis::connection('lua_proxy')->set(FsXmlMirrorKeys::version($tenantId), '5');

    $xml = app(DialplanXmlBuilder::class)->buildCachedDialplanXml($context, $destination, '2001');

    $epoch = (int) RoutingCacheVersion::getGlobal();
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();
    $this->mirrorKeys[] = FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, 5, $epoch);

    $conn = Redis::connection('lua_proxy');

    expect($conn->get(FsXmlMirrorKeys::version($tenantId)))->toBe('5')
        ->and($conn->get(FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, 5, $epoch)))->toBe($xml);
});

test('does not mirror a context that does not encode the tenant', function () {
    [$tenantId] = ($this->makeDialplanFixture)();

    // The Lua handler derives the tenant from the context name. A context that
    // carries no tenant was never written under a key FreeSWITCH could ask
    // for, so mirroring it would only add dead keys.
    app(DialplanXmlBuilder::class)->buildCachedDialplanXml('public', '2000', '2001');

    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();
    $this->mirrorKeys[] = FsXmlMirrorKeys::dialplan(
        $tenantId,
        'public',
        '2000',
        (int) RoutingCacheVersion::get($tenantId),
        (int) RoutingCacheVersion::getGlobal(),
    );

    expect(Redis::connection('lua_proxy')->exists(FsXmlMirrorKeys::dialplan(
        $tenantId,
        'public',
        '2000',
        (int) RoutingCacheVersion::get($tenantId),
        (int) RoutingCacheVersion::getGlobal(),
    )))->toBe(0);
});

test('moves the key when the routing revision is bumped', function () {
    [$tenantId, $context, $destination] = ($this->makeDialplanFixture)();

    $builder = app(DialplanXmlBuilder::class);
    $builder->buildCachedDialplanXml($context, $destination, '2001');

    $before = (int) RoutingCacheVersion::get($tenantId);
    RoutingCacheVersion::bump($tenantId);

    $builder->buildCachedDialplanXml($context, $destination, '2001');

    $after = (int) RoutingCacheVersion::get($tenantId);
    $epoch = (int) RoutingCacheVersion::getGlobal();

    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();
    $this->mirrorKeys[] = FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, $before, $epoch);
    $this->mirrorKeys[] = FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, $after, $epoch);

    // A bump must leave the next lookup under a key that did not exist before,
    // so Lua misses once and rebuilds instead of serving superseded routing.
    expect($after)->toBe($before + 1)
        ->and(Redis::connection('lua_proxy')->exists(
            FsXmlMirrorKeys::dialplan($tenantId, $context, $destination, $after, $epoch),
        ))->toBe(1);
});
