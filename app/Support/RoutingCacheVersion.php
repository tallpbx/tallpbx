<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks routing-data revisions so the dialplan caches invalidate on
 * translation or tenant-limit changes.
 *
 * The dialplan cache keys embed a per-tenant version number. Every write
 * through the NumberTranslationService or TenantLimitService bumps it, so
 * the next dialplan request misses the cache and rebuilds with the new
 * rules. Reads are plain cache gets — never database queries — so cached
 * dialplan responses stay query-free (the smoke suite's contract).
 */
class RoutingCacheVersion
{
    /**
     * The per-tenant cache key holding the current routing revision.
     */
    public static function key(int $tenantId): string
    {
        return 'freeswitch:routing-version:'.$tenantId;
    }

    /**
     * Resolve the cache store holding the routing revision. Defaults to the
     * application cache; configurable for deployments with unusual stores.
     */
    private static function store(): Repository
    {
        $storeName = config('freeswitch.xml_handler.routing_version_store');

        return is_string($storeName) && $storeName !== ''
            ? Cache::store($storeName)
            : Cache::driver();
    }

    /**
     * Bump the revision after a routing-data write.
     *
     * Some stores (notably the database store) cannot increment a missing
     * key and return a non-integer; fall back to seeding the key so the
     * invalidation never silently degrades to TTL-only.
     */
    public static function bump(int $tenantId): void
    {
        $store = self::store();

        if (! is_int($store->increment(self::key($tenantId)))) {
            $store->put(self::key($tenantId), static::get($tenantId) + 1);
        }
    }

    /**
     * Read the current revision (never a database query).
     */
    public static function get(int $tenantId): int
    {
        return (int) self::store()->get(self::key($tenantId), 0);
    }
}
