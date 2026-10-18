<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\FreeSwitchServiceInterface;
use App\Support\AclConfigurationCache;
use App\Support\Concerns\EscapesXml;
use App\Support\RoutingCacheVersion;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Support\Facades\Cache;
use Mockery;

/*
|--------------------------------------------------------------------------
| ACL Configuration Cache Tests
|--------------------------------------------------------------------------
*/

describe('acl configuration cache', function (): void {
    it('resolves the acl cache key and configured store', function (): void {
        expect(AclConfigurationCache::key())->toBe('freeswitch:acl');
        expect(AclConfigurationCache::store())->toBeInstanceOf(CacheRepositoryContract::class);
    });

    it('remembers and forgets the acl document under the configured store', function (): void {
        $result = AclConfigurationCache::remember(fn (): string => '<acl/>');

        expect($result)->toBe('<acl/>');
        expect(Cache::get('freeswitch:acl'))->toBe('<acl/>');

        AclConfigurationCache::forget();

        expect(Cache::has('freeswitch:acl'))->toBeFalse();
    });

    it('invalidates the cache and reloads FreeSWITCH ACLs', function (): void {
        Cache::put('freeswitch:acl', '<stale/>', 60);

        $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $freeSwitch->shouldReceive('connect')->once()->andReturn(true);
        $freeSwitch->shouldReceive('api')->once()->with('reloadacl')->andReturn('+OK');
        $freeSwitch->shouldReceive('disconnect')->once();
        app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

        AclConfigurationCache::invalidateAndReload();

        expect(Cache::has('freeswitch:acl'))->toBeFalse();
    });

    it('logs and continues when FreeSWITCH is unreachable', function (): void {
        Cache::put('freeswitch:acl', '<stale/>', 60);

        $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $freeSwitch->shouldReceive('connect')->once()->andReturn(false);
        app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

        AclConfigurationCache::invalidateAndReload();

        expect(Cache::has('freeswitch:acl'))->toBeFalse();
    });

    it('resolves the real FreeSWITCH service from the container without a binding', function (): void {
        Cache::put('freeswitch:acl', '<stale/>', 60);

        AclConfigurationCache::invalidateAndReload();

        expect(Cache::has('freeswitch:acl'))->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| XML Escaping Helper Tests
|--------------------------------------------------------------------------
*/

describe('xml escaping concern', function (): void {
    it('escapes XML special characters', function (): void {
        $host = new class
        {
            use EscapesXml;

            /**
             * Proxy the trait XML escaping helper.
             */
            public function escape(string $value): string
            {
                return $this->escapeXml($value);
            }
        };

        expect($host->escape('<name & "quoted">'))->toBe('&lt;name &amp; &quot;quoted&quot;&gt;');
    });
});

/*
|--------------------------------------------------------------------------
| Routing Cache Version Tests
|--------------------------------------------------------------------------
*/

describe('routing cache version', function (): void {
    beforeEach(function (): void {
        Cache::flush();
    });

    it('bumps and reads the routing revision', function (): void {
        expect(RoutingCacheVersion::get(42))->toBe(0);

        RoutingCacheVersion::bump(42);
        RoutingCacheVersion::bump(42);

        expect(RoutingCacheVersion::get(42))->toBe(2);
    });

    it('still bumps when the cache store cannot increment a missing key', function (): void {
        Cache::extend('nullinc', function () {
            $store = new class extends ArrayStore
            {
                /**
                 * Increment the value only when the key already exists.
                 */
                public function increment($key, $value = 1)
                {
                    if (parent::get($key) === null) {
                        return false;
                    }

                    return parent::increment($key, $value);
                }
            };

            return new Repository($store);
        });
        config(['cache.stores.nullinc' => ['driver' => 'nullinc']]);
        config(['freeswitch.xml_handler.routing_version_store' => 'nullinc']);

        RoutingCacheVersion::bump(42);

        expect(RoutingCacheVersion::get(42))->toBe(1);
    });
});
