<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantManager;
use App\Services\Xml\DirectoryXmlBuilder;
use App\Services\Xml\FsXmlMirrorKeys;
use App\Support\RoutingCacheVersion;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modules\SipAccounts\Models\SipAccount;

/*
 * Mirroring happens after the normal directory document is built or read from
 * cache. It must cover both FreeSWITCH lookup shapes, stay off when caching is
 * off, seed the version key on a cold start, and never write a document the
 * Lua handler would look up under a different identity.
 */

/**
 * The identity map key the Lua handler reads right now.
 *
 * The epoch is part of the key, so every assertion has to build the key from
 * the current global revision rather than a fixed string.
 */
function luaMirrorLookupKey(string $domain, string $user): string
{
    return FsXmlMirrorKeys::tenantLookup($domain, $user, (int) RoutingCacheVersion::getGlobal());
}

beforeEach(function (): void {
    config([
        'freeswitch.lua_proxy.enabled' => true,
        'freeswitch.lua_proxy.connection' => 'lua_proxy',
        'freeswitch.xml_handler.directory_cache_store' => 'array',
        'freeswitch.xml_handler.directory_cache_ttl' => 60,
    ]);

    $this->mirrorKeys = [];

    // Unique tenant IDs and domains keep parallel workers off each other's
    // raw Redis keys (every worker's SQLite database starts at tenant 1).
    $this->makeDirectoryFixture = function (): array {
        $tenant = Tenant::factory()->create(['id' => random_int(1_000_000, 2_000_000_000)]);
        $domain = TenantDomain::factory()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'mirror-'.Str::lower(Str::random(10)).'.test',
            'enabled' => true,
        ]);
        $user = 'u'.Str::lower(Str::random(8));

        SipAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'tenant_domain_id' => $domain->id,
            'auth_username' => $user,
            'user_context' => 'tenant_'.$tenant->id.'_internal',
            'enabled' => true,
        ]);

        app(TenantManager::class)->setTenantId((string) $tenant->id);

        $this->mirrorKeys[] = luaMirrorLookupKey($domain->domain, $user);

        return [$tenant->id, $domain->domain, $user];
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

test('mirrors directory xml, tenant lookup, and seeds version for real FreeSWITCH domain tag lookups', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();

    $xml = app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $version = (int) RoutingCacheVersion::get($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, $version);
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);

    $conn = Redis::connection('lua_proxy');
    expect($conn->get(luaMirrorLookupKey($domain, $user)))->toBe((string) $tenantId)
        ->and($conn->get(FsXmlMirrorKeys::version($tenantId)))->toBe((string) $version)
        ->and($conn->get(FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, $version)))->toBe($xml)
        ->and($xml)->toContain('<document');
});

test('mirrors directory xml and tenant lookup for synthetic user tag lookups', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();

    $xml = app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('user', $domain, $user, $user);

    $version = (int) RoutingCacheVersion::get($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::directory($tenantId, 'user', $domain, $user, $version);
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);

    $conn = Redis::connection('lua_proxy');
    expect($conn->get(luaMirrorLookupKey($domain, $user)))->toBe((string) $tenantId)
        ->and($conn->get(FsXmlMirrorKeys::directory($tenantId, 'user', $domain, $user, $version)))->toBe($xml);
});

test('does not mirror when key value differs from expected tag scope', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();

    // For the domain tag, key_value must match the domain.
    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, 'other.example');
    expect(Redis::connection('lua_proxy')->keys(luaMirrorLookupKey($domain, $user).'*'))->toBeEmpty();

    // For the user tag, key_value must match the username.
    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('user', $domain, $user, '9999');
    expect(Redis::connection('lua_proxy')->keys(luaMirrorLookupKey($domain, $user).'*'))->toBeEmpty();
});

test('does not mirror when directory caching is disabled', function () {
    config(['freeswitch.xml_handler.directory_cache_ttl' => 0]);
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();

    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    expect(Redis::connection('lua_proxy')->exists(luaMirrorLookupKey($domain, $user)))->toBe(0);
});

test('seeds version key on cold start without waiting for administrative bump', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();
    Redis::connection('lua_proxy')->del(FsXmlMirrorKeys::version($tenantId));
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);

    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    expect(Redis::connection('lua_proxy')->get(FsXmlMirrorKeys::version($tenantId)))
        ->toBe((string) RoutingCacheVersion::get($tenantId));
});

test('refreshes the mirror when the document is served from the php cache', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();

    $builder = app(DirectoryXmlBuilder::class);
    $builder->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $version = (int) RoutingCacheVersion::get($tenantId);
    $key = FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, $version);
    $this->mirrorKeys[] = $key;
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);

    Redis::connection('lua_proxy')->del($key);
    $builder->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $conn = Redis::connection('lua_proxy');
    expect($conn->exists($key))->toBe(1)
        ->and($conn->ttl($key))->toBeGreaterThan(0);
});

test('skips the mirror when the revision changes while the document renders', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();
    $this->mirrorKeys[] = luaMirrorLookupKey($domain, $user);
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, 0);
    $this->mirrorKeys[] = FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, 1);

    // The render queries SipAccount, so this listener simulates an admin save
    // landing while the document is being built.
    SipAccount::retrieved(function () use ($tenantId): void {
        RoutingCacheVersion::bump($tenantId);
    });

    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $conn = Redis::connection('lua_proxy');
    expect($conn->exists(luaMirrorLookupKey($domain, $user)))->toBe(0)
        ->and($conn->exists(FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, 0)))->toBe(0)
        ->and($conn->exists(FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, 1)))->toBe(0);
});

test('keys the identity map by the global epoch so an ownership change misses', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();

    $builder = app(DirectoryXmlBuilder::class);
    $builder->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $epoch = RoutingCacheVersion::getGlobal();
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();
    $this->mirrorKeys[] = 'pbx:fsxml:tenant:user:'.$domain.':'.$user.':e'.$epoch;

    $conn = Redis::connection('lua_proxy');

    // Lua reads the epoch from Redis before it can build the map key, so the
    // read path has to seed it on a cold start the same way it seeds the
    // tenant revision.
    expect($conn->get(FsXmlMirrorKeys::globalVersion()))->toBe((string) $epoch)
        ->and($conn->get(luaMirrorLookupKey($domain, $user)))->toBe((string) $tenantId);

    // An ownership change moves the epoch. The entry written before the change
    // must not be readable under the new epoch.
    RoutingCacheVersion::bumpGlobal();
    $builder->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $newEpoch = RoutingCacheVersion::getGlobal();
    $this->mirrorKeys[] = 'pbx:fsxml:tenant:user:'.$domain.':'.$user.':e'.$newEpoch;

    expect($newEpoch)->toBeGreaterThan($epoch)
        ->and($conn->get(luaMirrorLookupKey($domain, $user)))->toBe((string) $tenantId);
});

test('skips the mirror when the global epoch changes while the document renders', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();
    $this->mirrorKeys[] = FsXmlMirrorKeys::globalVersion();
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);

    // A SIP account save lands while the document is being built.
    SipAccount::retrieved(function (): void {
        RoutingCacheVersion::bumpGlobal();
    });

    app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $conn = Redis::connection('lua_proxy');

    // Nothing may be published under an epoch the document was not rendered
    // for, or Lua could serve one tenant's directory under another's identity.
    expect($conn->keys(luaMirrorLookupKey($domain, $user).'*'))->toBeEmpty();
});

test('publishes under the mirror revision when the cache store was flushed', function () {
    [$tenantId, $domain, $user] = ($this->makeDirectoryFixture)();

    // tpbx optimize:clear empties DB 1 while DB 0 keeps the last revision and
    // its payload keys. The read path must neither roll the revision back nor
    // publish under a revision the Lua handler will never read.
    Redis::connection('lua_proxy')->set(FsXmlMirrorKeys::version($tenantId), '5');
    $this->mirrorKeys[] = FsXmlMirrorKeys::version($tenantId);
    $this->mirrorKeys[] = luaMirrorLookupKey($domain, $user);
    $this->mirrorKeys[] = FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, 5);

    $xml = app(DirectoryXmlBuilder::class)->buildCachedDirectoryXml('domain', $domain, $user, $domain);

    $conn = Redis::connection('lua_proxy');
    expect($conn->get(FsXmlMirrorKeys::version($tenantId)))->toBe('5')
        ->and($conn->get(FsXmlMirrorKeys::directory($tenantId, 'domain', $domain, $user, 5)))->toBe($xml);
});
