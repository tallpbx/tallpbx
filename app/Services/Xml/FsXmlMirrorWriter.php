<?php

declare(strict_types=1);

namespace App\Services\Xml;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Mirrors Laravel-rendered directory XML into the raw Redis namespace that
 * the optional FreeSWITCH Lua handler reads.
 *
 * Every write is best-effort. A Redis outage, a missing connection, or a
 * failing command must never surface to the caller: the panel save that
 * triggered the write still has to succeed. Failures are logged at debug
 * level, without keys or values, so SIP usernames and passwords never reach
 * the log.
 *
 * The writer bypasses the Cache facade on purpose. Laravel prefixes cache
 * keys and PHP-serialises non-numeric values, both of which would break the
 * byte-for-byte contract with scripts/freeswitch/tallpbx_xml_handler.lua.
 */
class FsXmlMirrorWriter
{
    /**
     * Largest document that is mirrored. Bigger documents stay uncached in
     * Lua (the lookup falls through to mod_xml_curl) so a very large tenant
     * cannot turn one key into a multi-megabyte value.
     */
    public const MAX_PAYLOAD_BYTES = 262144;

    /**
     * Whether the feature flag is on for this writer instance.
     */
    private readonly bool $enabled;

    /**
     * The unprefixed Redis connection name from configuration.
     */
    private readonly string $connection;

    /**
     * Read the feature flag and connection name once per instance.
     *
     * The Redis connection itself is resolved per call so that a Redis
     * outage during a panel save cannot fail construction.
     */
    public function __construct()
    {
        $this->enabled = (bool) config('freeswitch.lua_proxy.enabled', false);
        $this->connection = (string) config('freeswitch.lua_proxy.connection', 'lua_proxy');
    }

    /**
     * Whether mirror writes are enabled for this instance.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Map a request's domain and user to the tenant PHP resolved.
     *
     * The Lua handler never guesses a tenant: when this key is missing, the
     * lookup misses and FreeSWITCH asks PHP instead.
     */
    public function writeTenantLookup(string $domain, string $user, int $tenantId): void
    {
        $this->writeWithExpiry(FsXmlMirrorKeys::tenantLookup($domain, $user), (string) $tenantId);
    }

    /**
     * Store a rendered directory document under its versioned payload key.
     */
    public function writeDirectory(int $tenantId, string $tagName, string $domain, string $user, int $version, string $xml): void
    {
        $this->writeWithExpiry(
            FsXmlMirrorKeys::directory($tenantId, $tagName, $domain, $user, $version),
            $xml,
        );
    }

    /**
     * Store a tenant routing revision. Version keys never expire, so a bump
     * survives the payload TTL and the next lookup misses as intended.
     */
    public function writeVersion(int $tenantId, int $version): void
    {
        $this->writeWithoutExpiry(FsXmlMirrorKeys::version($tenantId), (string) $version);
    }

    /**
     * Store the system-wide routing revision.
     */
    public function writeGlobalVersion(int $version): void
    {
        $this->writeWithoutExpiry(FsXmlMirrorKeys::globalVersion(), (string) $version);
    }

    /**
     * Seed a tenant routing revision only when no key exists yet.
     *
     * Revision keys are owned by RoutingCacheVersion::bump(). A read path
     * that sampled a revision must never overwrite a newer one, or a bump
     * that lands mid-request would be rolled back and Lua would serve the
     * superseded document again.
     */
    public function writeVersionIfMissing(int $tenantId, int $version): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            Redis::connection($this->connection)->setnx(FsXmlMirrorKeys::version($tenantId), (string) $version);
        } catch (\Throwable $e) {
            $this->logFailure('write', $e);
        }
    }

    /**
     * Read the mirrored tenant revision, if one exists.
     *
     * Used by RoutingCacheVersion::bump() to keep the revision monotonic
     * after the cache store is flushed while mirror payload keys survive.
     */
    public function readVersion(int $tenantId): ?int
    {
        return $this->readInt(FsXmlMirrorKeys::version($tenantId));
    }

    /**
     * Read the mirrored global revision, if one exists.
     */
    public function readGlobalVersion(): ?int
    {
        return $this->readInt(FsXmlMirrorKeys::globalVersion());
    }

    /**
     * Write a value that expires with the directory cache TTL.
     *
     * Returns early when the feature is off, when directory caching is off
     * (there is then nothing to mirror), or when the value exceeds the size
     * cap.
     */
    private function writeWithExpiry(string $key, string $value): void
    {
        if (! $this->enabled) {
            return;
        }

        $ttl = max(0, (int) config('freeswitch.xml_handler.directory_cache_ttl', 0));

        if ($ttl === 0) {
            return;
        }

        if (strlen($value) > self::MAX_PAYLOAD_BYTES) {
            Log::debug('Lua proxy mirror skipped an oversized document.', [
                'bytes' => strlen($value),
            ]);

            return;
        }

        try {
            Redis::connection($this->connection)->set($key, $value, 'EX', $ttl);
        } catch (\Throwable $e) {
            $this->logFailure('write', $e);
        }
    }

    /**
     * Write a value that persists until it is overwritten.
     */
    private function writeWithoutExpiry(string $key, string $value): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            Redis::connection($this->connection)->set($key, $value);
        } catch (\Throwable $e) {
            $this->logFailure('write', $e);
        }
    }

    /**
     * Read a numeric value, returning null on a miss, a non-numeric value,
     * or any Redis failure.
     */
    private function readInt(string $key): ?int
    {
        if (! $this->enabled) {
            return null;
        }

        try {
            $value = Redis::connection($this->connection)->get($key);
        } catch (\Throwable $e) {
            $this->logFailure('read', $e);

            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Log a failed mirror operation without leaking keys or payloads.
     */
    private function logFailure(string $operation, \Throwable $e): void
    {
        Log::debug('Lua proxy mirror operation failed.', [
            'operation' => $operation,
            'error' => $e->getMessage(),
        ]);
    }
}
