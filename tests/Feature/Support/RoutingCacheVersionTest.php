<?php

declare(strict_types=1);

use App\Support\RoutingCacheVersion;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

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
    // Database cache stores return false (never create the key) when
    // incrementing a missing key; the bump must fall back to a put so
    // version-based invalidation never silently degrades to TTL-only.
    // Register an array-backed driver that mimics that behavior.
    Cache::extend('nullinc', function () {
        // ArrayStore with database-store increment semantics: incrementing a
        // missing key returns false instead of creating it.
        $store = new class extends ArrayStore
        {
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
