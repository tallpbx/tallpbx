<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks routing-data revisions so the dialplan and directory caches
 * invalidate on translation, gateway, or tenant-limit changes.
 *
 * The cache keys embed a per-tenant version number. Every write through the
 * services that change routing data bumps it, so the next XML request misses
 * the cache and rebuilds with the new rules. Reads are plain cache gets —
 * never database queries — so cached XML responses stay query-free.
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
     * The global cache key holding the system-wide routing revision
     * (e.g. custom SIP scanner signatures affecting all public dialplans).
     */
    public static function globalKey(): string
    {
        return 'freeswitch:routing-version:global';
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
     * A flushed cache store forgets the revision, so a missing key is
     * seeded from the current value plus one. Some stores (notably the
     * database store) cannot increment a missing key and return a
     * non-integer; fall back to seeding the key so the invalidation never
     * silently degrades to TTL-only.
     */
    public static function bump(int $tenantId): void
    {
        $store = self::store();
        $key = self::key($tenantId);

        if (! $store->has($key)) {
            $store->put($key, static::get($tenantId) + 1);
        } elseif (! is_int($store->increment($key))) {
            $store->put($key, static::get($tenantId) + 1);
        }
    }

    /**
     * Bump the global revision after a system-wide routing-data write
     * (e.g. custom SIP scanner signature updates).
     */
    public static function bumpGlobal(): void
    {
        $store = self::store();
        $key = self::globalKey();

        if (! $store->has($key)) {
            $store->put($key, static::getGlobal() + 1);
        } elseif (! is_int($store->increment($key))) {
            $store->put($key, static::getGlobal() + 1);
        }
    }

    /**
     * Read the current revision (never a database query).
     */
    public static function get(int $tenantId): int
    {
        return (int) self::store()->get(self::key($tenantId), 0);
    }

    /**
     * Read the current global revision (never a database query).
     */
    public static function getGlobal(): int
    {
        return (int) self::store()->get(self::globalKey(), 0);
    }
}
