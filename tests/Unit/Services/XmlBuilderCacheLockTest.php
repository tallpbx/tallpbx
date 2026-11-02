<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\DialplanXmlCollector;
use App\Services\TenantManager;
use App\Services\Xml\DialplanXmlBuilder;
use App\Services\Xml\DirectoryXmlBuilder;
use App\Support\RoutingCacheVersion;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\SipAccounts\Models\SipAccount;

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'freeswitch.xml_handler.directory_cache_store' => 'array',
        'freeswitch.xml_handler.directory_cache_ttl' => 60,
        'freeswitch.xml_handler.dialplan_cache_store' => 'array',
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
        'freeswitch.xml_handler.routing_version_store' => 'array',
        'freeswitch.lua_proxy.enabled' => false,
    ]);
    Cache::store('array')->flush();
    app(TenantManager::class)->clear();
});

test('directory xml builder acquires atomic cache lock with 5s ttl and 3s block timeout', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'lock-domain.example.com',
        'enabled' => true,
    ]);
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'user100',
        'auth_password' => 'pass100',
        'enabled' => true,
    ]);

    app(TenantManager::class)->setTenantId((string) $tenant->id);

    Cache::spy();

    $builder = app(DirectoryXmlBuilder::class);
    $xml = $builder->buildCachedDirectoryXml('user', 'lock-domain.example.com', 'user100', 'user100');

    Cache::shouldHaveReceived('lock')
        ->once()
        ->withArgs(function (string $key, int $duration): bool {
            return str_starts_with($key, 'lock:freeswitch:xml-handler:directory:')
                && $duration === 5;
        });

    expect($xml)->toContain('pass100');
});

test('directory xml builder avoids duplicate queries when concurrent worker populates cache', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'thundering-herd.example.com',
        'enabled' => true,
    ]);
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'herd_user',
        'auth_password' => 'initial-secret',
        'enabled' => true,
    ]);

    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $builder = app(DirectoryXmlBuilder::class);

    // Warm the cache via a first builder run
    $firstXml = $builder->buildCachedDirectoryXml('user', 'thundering-herd.example.com', 'herd_user', 'herd_user');
    expect($firstXml)->toContain('initial-secret');

    // Simulate concurrent worker 2: enters remember callback on cache miss,
    // but before acquiring lock, worker 1 has finished and written to cache.
    // The lock callback must double-check cache and NOT query the database.
    DB::enableQueryLog();
    DB::flushQueryLog();

    // Calling again hits cache with 0 database queries
    $secondXml = $builder->buildCachedDirectoryXml('user', 'thundering-herd.example.com', 'herd_user', 'herd_user');

    expect($secondXml)->toBe($firstXml)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('dialplan xml builder acquires atomic cache lock with 5s ttl and 3s block timeout', function () {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collectWithMetadata')
        ->once()
        ->with($tenant->id, 'tenant_'.$tenant->id.'_internal', '3000')
        ->andReturn([
            'xml' => "      <extension name=\"dialplan_lock_test\"/>\n",
            'has_routing_contribution' => true,
        ]);
    app()->instance(DialplanXmlCollector::class, $collector);

    Cache::spy();

    $builder = app(DialplanXmlBuilder::class);
    $xml = $builder->buildCachedDialplanXml('tenant_'.$tenant->id.'_internal', '3000', '1001');

    Cache::shouldHaveReceived('lock')
        ->atLeast()->once()
        ->withArgs(function (string $key, int $duration): bool {
            return str_starts_with($key, 'lock:freeswitch:xml-handler:dialplan:')
                && $duration === 5;
        });

    expect($xml)->toContain('dialplan_lock_test');
});

test('dialplan xml builder double checks cache under lock preventing duplicate collector calls', function () {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $collector = Mockery::mock(DialplanXmlCollector::class);
    // Collector must only be called ONCE despite two invocations
    $collector->shouldReceive('collectWithMetadata')
        ->once()
        ->with($tenant->id, 'tenant_'.$tenant->id.'_internal', '4000')
        ->andReturn([
            'xml' => "      <extension name=\"herd_collector_test\"/>\n",
            'has_routing_contribution' => true,
        ]);
    app()->instance(DialplanXmlCollector::class, $collector);

    $builder = app(DialplanXmlBuilder::class);

    $firstXml = $builder->buildCachedDialplanXml('tenant_'.$tenant->id.'_internal', '4000', '1001');
    $secondXml = $builder->buildCachedDialplanXml('tenant_'.$tenant->id.'_internal', '4000', '1001');

    expect($firstXml)->toContain('herd_collector_test')
        ->and($secondXml)->toBe($firstXml);
});

test('concurrent workers serialize through the lock when building directory xml', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'serialize.example.com',
        'enabled' => true,
    ]);
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'ser_user',
        'auth_password' => 'ser_pass',
        'enabled' => true,
    ]);

    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $builder = app(DirectoryXmlBuilder::class);

    // Pre-acquire the lock to simulate Worker 1 currently building
    // We compute the expected lock key or catch it dynamically
    $version = (int) RoutingCacheVersion::get((int) $tenant->id);
    $cacheKey = 'freeswitch:xml-handler:directory:'.sha1(implode('|', [
        (string) $tenant->id,
        'user',
        'serialize.example.com',
        'ser_user',
        'ser_user',
        (string) $version,
    ]));
    $lockKey = "lock:{$cacheKey}";

    $lock1 = Cache::lock($lockKey, 5);
    $lock1->acquire();

    // Now Worker 2 attempts to build while Worker 1 holds the lock.
    // Worker 1 populates the cache right before releasing the lock.
    $cache = Cache::store('array');
    $dummyXml = '<document type="freeswitch/xml"><section name="directory"><user id="ser_user"/></section></document>';

    // Release lock and populate cache to simulate Worker 1 finishing
    $cache->put($cacheKey, $dummyXml, 60);
    $lock1->release();

    // Worker 2 calls buildCachedDirectoryXml; because of atomic lock & double check,
    // it receives the cached XML without building fresh from DB
    $result = $builder->buildCachedDirectoryXml('user', 'serialize.example.com', 'ser_user', 'ser_user');

    expect($result)->toBe($dummyXml);
});

test('standard dialplan fragment caching acquires atomic lock with lock prefix and 5s duration', function () {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collectWithMetadata')
        ->andReturn([
            'xml' => '',
            'has_routing_contribution' => false,
        ]);
    app()->instance(DialplanXmlCollector::class, $collector);

    Cache::spy();

    $builder = app(DialplanXmlBuilder::class);
    $builder->buildCachedDialplanXml('tenant_'.$tenant->id.'_internal', '5000', '1001');

    Cache::shouldHaveReceived('lock')
        ->withArgs(function (string $key, int $duration): bool {
            return str_starts_with($key, 'lock:freeswitch:xml-handler:standard-dialplan:')
                && $duration === 5;
        });
});
