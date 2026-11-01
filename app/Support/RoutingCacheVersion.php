<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Xml\FsXmlMirrorWriter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tracks routing-data revisions so the dialplan and directory caches
 * invalidate on translation, gateway, or tenant-limit changes.
 *
 * The cache keys embed a per-tenant version number. Every write through the
 * services that change routing data bumps it, so the next XML request misses
 * the cache and rebuilds with the new rules. Reads are plain cache gets —
 * never database queries — so cached XML responses stay query-free.
 *
 * Each bump is also mirrored into the raw Redis namespace read by the
 * optional FreeSWITCH Lua handler. That mirror write is best-effort: if
 * Redis is unavailable, the bump still succeeds and the Lua proxy simply
 * misses until Redis returns.
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
     * A flushed cache store forgets the revision while mirrored payload keys
     * in Redis DB 0 survive (`tpbx optimize:clear` flushes DB 1 only). When
     * the store has no key, seed from the higher of the store and mirror
     * values so the next revision can never collide with documents that are
     * still readable by the Lua handler.
     *
     * Some stores (notably the database store) cannot increment a missing
     * key and return a non-integer; fall back to seeding the key so the
     * invalidation never silently degrades to TTL-only.
     */
    public static function bump(int $tenantId): void
    {
        $store = self::store();
        $key = self::key($tenantId);
        $writer = self::mirrorWriter();

        if (! $store->has($key)) {
            $mirrorVersion = $writer?->readVersion($tenantId) ?? 0;
            $store->put($key, max(static::get($tenantId), $mirrorVersion) + 1);
        } elseif (! is_int($store->increment($key))) {
            $store->put($key, static::get($tenantId) + 1);
        }

        self::mirror($writer, fn (FsXmlMirrorWriter $mirror) => $mirror->writeVersion($tenantId, static::get($tenantId)));
    }

    /**
     * Bump the global revision after a system-wide routing-data write
     * (e.g. custom SIP scanner signature updates).
     */
    public static function bumpGlobal(): void
    {
        $store = self::store();
        $key = self::globalKey();
        $writer = self::mirrorWriter();

        if (! $store->has($key)) {
            $mirrorVersion = $writer?->readGlobalVersion() ?? 0;
            $store->put($key, max(static::getGlobal(), $mirrorVersion) + 1);
        } elseif (! is_int($store->increment($key))) {
            $store->put($key, static::getGlobal() + 1);
        }

        self::mirror($writer, fn (FsXmlMirrorWriter $mirror) => $mirror->writeGlobalVersion(static::getGlobal()));
    }

    /**
     * Resolve the optional Lua mirror writer, if the feature is enabled.
     */
    private static function mirrorWriter(): ?FsXmlMirrorWriter
    {
        $writer = app(FsXmlMirrorWriter::class);

        return $writer->enabled() ? $writer : null;
    }

    /**
     * Run one mirror write. Never throws: a mirror failure may cost cache
     * hits, but it must not fail the write that caused it.
     */
    private static function mirror(?FsXmlMirrorWriter $writer, callable $write): void
    {
        if ($writer === null) {
            return;
        }

        try {
            $write($writer);
        } catch (\Throwable $e) {
            Log::debug('Routing cache version mirror failed.', [
                'error' => $e->getMessage(),
            ]);
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
