<?php

declare(strict_types=1);

use App\Services\FreeSwitchServiceInterface;
use App\Support\AclConfigurationCache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

it('resolves the acl cache key and configured store', function (): void {
    expect(AclConfigurationCache::key())->toBe('freeswitch:acl');
    expect(AclConfigurationCache::store())->toBeInstanceOf(Repository::class);
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
    // No mock bound: the hook must resolve the container's singleton (the
    // FreeSwitchServiceInterface binding), never autowire the concrete class,
    // and must complete without throwing when ESL is unreachable.
    Cache::put('freeswitch:acl', '<stale/>', 60);

    AclConfigurationCache::invalidateAndReload();

    expect(Cache::has('freeswitch:acl'))->toBeFalse();
});
