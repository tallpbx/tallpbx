<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\DialplanXmlCollector;
use App\Services\TenantManager;
use App\Services\Xml\DialplanXmlBuilder;
use App\Services\Xml\DirectoryXmlBuilder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;
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

test('directory xml builder acquires atomic cache lock from repository with 5s ttl and 3s block timeout', function () {
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

    $realStore = Cache::store('array');
    $cacheMock = Mockery::mock($realStore)->makePartial();
    Cache::shouldReceive('store')->with('array')->andReturn($cacheMock);
    Cache::shouldReceive('store')->withAnyArgs()->andReturn($realStore);
    Cache::shouldReceive('driver')->andReturn($cacheMock);

    $lockSpy = Mockery::mock(Lock::class);
    $lockSpy->shouldReceive('block')
        ->once()
        ->with(3, Mockery::type('callable'))
        ->andReturnUsing(fn ($timeout, $callback) => $callback());

    $cacheMock->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^lock:freeswitch:xml-handler:directory:/'), 5)
        ->andReturn($lockSpy);

    $builder = app(DirectoryXmlBuilder::class);
    $xml = $builder->buildCachedDirectoryXml('user', 'lock-domain.example.com', 'user100', 'user100');

    expect($xml)->toContain('pass100');
});

test('directory xml builder guarantees cache is warm before lock is released', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'release-timing.example.com',
        'enabled' => true,
    ]);
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'tenant_domain_id' => $domain->id,
        'auth_username' => 'timing_user',
        'auth_password' => 'timing_secret',
        'enabled' => true,
    ]);

    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $realStore = Cache::store('array');
    $cacheMock = Mockery::mock($realStore)->makePartial();
    Cache::shouldReceive('store')->with('array')->andReturn($cacheMock);
    Cache::shouldReceive('store')->withAnyArgs()->andReturn($realStore);
    Cache::shouldReceive('driver')->andReturn($cacheMock);

    $cacheKeyInspected = null;
    $cacheWasWarmBeforeLockRelease = false;

    $lockSpy = Mockery::mock(Lock::class);
    $lockSpy->shouldReceive('block')
        ->once()
        ->with(3, Mockery::type('callable'))
        ->andReturnUsing(function ($timeout, $callback) use ($realStore, &$cacheKeyInspected, &$cacheWasWarmBeforeLockRelease) {
            // Execute the lock callback (which builds XML and must call $cache->put before returning)
            $result = $callback();

            // Crucial verification: at the moment the callback returns (BEFORE lock release in finally),
            // the cache MUST already be populated with the result!
            if ($cacheKeyInspected !== null && $realStore->has($cacheKeyInspected)) {
                $cacheWasWarmBeforeLockRelease = ($realStore->get($cacheKeyInspected) === $result);
            }

            return $result;
        });

    $cacheMock->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^lock:freeswitch:xml-handler:directory:/'), 5)
        ->andReturnUsing(function ($key) use ($lockSpy, &$cacheKeyInspected) {
            $cacheKeyInspected = substr($key, 5); // Strip 'lock:' prefix

            return $lockSpy;
        });

    $builder = app(DirectoryXmlBuilder::class);
    $xml = $builder->buildCachedDirectoryXml('user', 'release-timing.example.com', 'timing_user', 'timing_user');

    expect($cacheWasWarmBeforeLockRelease)->toBeTrue()
        ->and($xml)->toContain('timing_secret');
});

test('directory xml builder double-check branch prevents duplicate queries when concurrent worker populates cache', function () {
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
        'auth_password' => 'real-database-password',
        'enabled' => true,
    ]);

    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $realStore = Cache::store('array');
    $cacheMock = Mockery::mock($realStore)->makePartial();
    Cache::shouldReceive('store')->with('array')->andReturn($cacheMock);
    Cache::shouldReceive('store')->withAnyArgs()->andReturn($realStore);
    Cache::shouldReceive('driver')->andReturn($cacheMock);

    $syntheticXml = '<document type="freeswitch/xml"><section name="directory"><user id="from_concurrent_worker"/></section></document>';

    $cacheMock->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^lock:freeswitch:xml-handler:directory:/'), 5)
        ->andReturnUsing(function ($lockKey) use ($realStore, $syntheticXml) {
            $cacheKey = substr($lockKey, 5); // Extract raw cacheKey from 'lock:'

            $mock = Mockery::mock(Lock::class);
            $mock->shouldReceive('block')
                ->once()
                ->with(3, Mockery::type('callable'))
                ->andReturnUsing(function ($timeout, $callback) use ($realStore, $cacheKey, $syntheticXml) {
                    // Pre-populate cache directly before callback runs, precisely simulating
                    // the preceding worker finishing its build while this worker blocked on the lock:
                    $realStore->put($cacheKey, $syntheticXml, 60);

                    return $callback();
                });

            return $mock;
        });

    DB::enableQueryLog();
    DB::flushQueryLog();

    $builder = app(DirectoryXmlBuilder::class);
    // Outer cache was cold, so remember() enters its closure and acquires the lock.
    // The lock callback finds the cache populated by the concurrent worker,
    // hits the double-check branch, and returns synthetic XML without querying the database.
    $resultXml = $builder->buildCachedDirectoryXml('user', 'thundering-herd.example.com', 'herd_user', 'herd_user');

    expect($resultXml)->toBe($syntheticXml)
        ->and($resultXml)->not->toContain('real-database-password')
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('dialplan xml builder acquires atomic cache lock from repository with 5s ttl and 3s block timeout', function () {
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

    $realStore = Cache::store('array');
    $cacheMock = Mockery::mock($realStore)->makePartial();
    Cache::shouldReceive('store')->with('array')->andReturn($cacheMock);
    Cache::shouldReceive('store')->withAnyArgs()->andReturn($realStore);
    Cache::shouldReceive('driver')->andReturn($cacheMock);

    $lockSpy = Mockery::mock(Lock::class);
    $lockSpy->shouldReceive('block')
        ->atLeast()->once()
        ->with(3, Mockery::type('callable'))
        ->andReturnUsing(fn ($timeout, $callback) => $callback());

    $cacheMock->shouldReceive('lock')
        ->atLeast()->once()
        ->with(Mockery::pattern('/^lock:freeswitch:xml-handler:dialplan:/'), 5)
        ->andReturn($lockSpy);

    $builder = app(DialplanXmlBuilder::class);
    $xml = $builder->buildCachedDialplanXml('tenant_'.$tenant->id.'_internal', '3000', '1001');

    expect($xml)->toContain('dialplan_lock_test');
});

test('dialplan xml builder guarantees cache is warm before lock is released', function () {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collectWithMetadata')
        ->once()
        ->andReturn([
            'xml' => "      <extension name=\"warm_check_dialplan\"/>\n",
            'has_routing_contribution' => true,
        ]);
    app()->instance(DialplanXmlCollector::class, $collector);

    $realStore = Cache::store('array');
    $cacheMock = Mockery::mock($realStore)->makePartial();
    Cache::shouldReceive('store')->with('array')->andReturn($cacheMock);
    Cache::shouldReceive('store')->withAnyArgs()->andReturn($realStore);
    Cache::shouldReceive('driver')->andReturn($cacheMock);

    $dialplanCacheKey = null;
    $cacheWasWarmBeforeLockRelease = false;

    $cacheMock->shouldReceive('lock')
        ->atLeast()->once()
        ->with(Mockery::pattern('/^lock:freeswitch:xml-handler:/'), 5)
        ->andReturnUsing(function ($lockKey) use ($realStore, &$dialplanCacheKey, &$cacheWasWarmBeforeLockRelease) {
            $mock = Mockery::mock(Lock::class);
            $mock->shouldReceive('block')
                ->once()
                ->with(3, Mockery::type('callable'))
                ->andReturnUsing(function ($timeout, $callback) use ($realStore, $lockKey, &$dialplanCacheKey, &$cacheWasWarmBeforeLockRelease) {
                    $result = $callback();

                    if (str_starts_with($lockKey, 'lock:freeswitch:xml-handler:dialplan:')) {
                        $dialplanCacheKey = substr($lockKey, 5);
                        if ($realStore->has($dialplanCacheKey)) {
                            $cacheWasWarmBeforeLockRelease = ($realStore->get($dialplanCacheKey) === $result);
                        }
                    }

                    return $result;
                });

            return $mock;
        });

    $builder = app(DialplanXmlBuilder::class);
    $xml = $builder->buildCachedDialplanXml('tenant_'.$tenant->id.'_internal', '3500', '1001');

    expect($cacheWasWarmBeforeLockRelease)->toBeTrue()
        ->and($xml)->toContain('warm_check_dialplan');
});

test('dialplan xml builder double-check branch prevents duplicate collector calls when concurrent worker populates cache', function () {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $collector = Mockery::mock(DialplanXmlCollector::class);
    // Collector must NEVER be called because the double-check branch satisfies the request
    $collector->shouldNotReceive('collectWithMetadata');
    app()->instance(DialplanXmlCollector::class, $collector);

    $realStore = Cache::store('array');
    $cacheMock = Mockery::mock($realStore)->makePartial();
    Cache::shouldReceive('store')->with('array')->andReturn($cacheMock);
    Cache::shouldReceive('store')->withAnyArgs()->andReturn($realStore);
    Cache::shouldReceive('driver')->andReturn($cacheMock);

    $syntheticXml = '<document type="freeswitch/xml"><section name="dialplan"><extension name="concurrent_dialplan"/></section></document>';

    $cacheMock->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^lock:freeswitch:xml-handler:dialplan:/'), 5)
        ->andReturnUsing(function ($lockKey) use ($realStore, $syntheticXml) {
            $cacheKey = substr($lockKey, 5);

            $mock = Mockery::mock(Lock::class);
            $mock->shouldReceive('block')
                ->once()
                ->with(3, Mockery::type('callable'))
                ->andReturnUsing(function ($timeout, $callback) use ($realStore, $cacheKey, $syntheticXml) {
                    // Populate cache before callback executes (simulating concurrent worker)
                    $realStore->put($cacheKey, $syntheticXml, 60);

                    return $callback();
                });

            return $mock;
        });

    $builder = app(DialplanXmlBuilder::class);
    $resultXml = $builder->buildCachedDialplanXml('tenant_'.$tenant->id.'_internal', '4000', '1001');

    expect($resultXml)->toBe($syntheticXml);
});

test('standard dialplan fragment caching double checks cache under lock preventing duplicate queries', function () {
    $tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $collector = Mockery::mock(DialplanXmlCollector::class);
    $collector->shouldReceive('collectWithMetadata')
        ->andReturn([
            'xml' => '',
            'has_routing_contribution' => false,
        ]);
    app()->instance(DialplanXmlCollector::class, $collector);

    $translations = Mockery::mock(NumberTranslationServiceInterface::class);
    $translations->shouldReceive('translate')->andReturnArg(0);
    app()->instance(NumberTranslationServiceInterface::class, $translations);

    $realStore = Cache::store('array');
    $cacheMock = Mockery::mock($realStore)->makePartial();
    Cache::shouldReceive('store')->with('array')->andReturn($cacheMock);
    Cache::shouldReceive('store')->withAnyArgs()->andReturn($realStore);
    Cache::shouldReceive('driver')->andReturn($cacheMock);

    $syntheticStandard = [
        'xml' => '      <extension name="concurrent_standard_dialplan"/>',
        'has_dialplans' => true,
    ];

    $cacheMock->shouldReceive('lock')
        ->atLeast()->once()
        ->with(Mockery::pattern('/^lock:freeswitch:xml-handler:/'), 5)
        ->andReturnUsing(function ($lockKey) use ($realStore, $syntheticStandard) {
            $mock = Mockery::mock(Lock::class);
            $mock->shouldReceive('block')
                ->once()
                ->with(3, Mockery::type('callable'))
                ->andReturnUsing(function ($timeout, $callback) use ($realStore, $lockKey, $syntheticStandard) {
                    if (str_starts_with($lockKey, 'lock:freeswitch:xml-handler:standard-dialplan:')) {
                        $cacheKey = substr($lockKey, 5);
                        $realStore->put($cacheKey, $syntheticStandard, 60);
                    }

                    return $callback();
                });

            return $mock;
        });

    DB::enableQueryLog();
    DB::flushQueryLog();

    $builder = app(DialplanXmlBuilder::class);
    $xml = $builder->buildCachedDialplanXml('tenant_'.$tenant->id.'_internal', '5000', '1001');

    // Proves that standard dialplan was served from the synthetic cached fragment inside the lock,
    // and no database queries were run for Dialplan records
    expect($xml)->toContain('concurrent_standard_dialplan')
        ->and(DB::getQueryLog())->toBeEmpty();
});
