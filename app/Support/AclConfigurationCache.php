<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\FreeSwitchServiceInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Owns the FreeSWITCH acl.conf cache key, store, and live-reload wiring.
 *
 * Both the XML handler (builder) and the access-control/domain CRUD hooks
 * resolve the store here, so the cache key and store can never drift apart.
 */
class AclConfigurationCache
{
    /**
     * The single cache key holding the generated acl.conf document.
     */
    public static function key(): string
    {
        return 'freeswitch:acl';
    }

    /**
     * Resolve the configured ACL cache store, falling back like the dialplan cache.
     */
    public static function store(): Repository
    {
        $cacheStore = config('freeswitch.xml_handler.acl_cache_store');

        return is_string($cacheStore) && $cacheStore !== ''
            ? Cache::store($cacheStore)
            : Cache::driver();
    }

    /**
     * Return the cached acl.conf document, rebuilding it through the given builder.
     */
    public static function remember(callable $builder): string
    {
        return self::store()->remember(
            self::key(),
            (int) config('freeswitch.xml_handler.acl_cache_ttl', 5),
            $builder,
        );
    }

    /**
     * Drop the cached acl.conf document.
     */
    public static function forget(): void
    {
        self::store()->forget(self::key());
    }

    /**
     * Drop the cached document and ask FreeSWITCH to reload its ACLs.
     *
     * ESL unavailability is non-fatal: the short cache TTL bounds staleness
     * and the next reloadacl (or restart) converges.
     */
    public static function invalidateAndReload(): void
    {
        self::forget();

        try {
            // Resolve the container singleton (the interface binding); the
            // concrete class cannot be autowired (required scalar args).
            $freeSwitch = app(FreeSwitchServiceInterface::class);

            // Short probe timeout so the panel does not hang for the full
            // ESL timeout when FreeSWITCH is not running (1 second vs 10).
            if (! $freeSwitch->connect(timeout: 1)) {
                Log::warning('FreeSWITCH is unreachable; acl.conf cache invalidated but reloadacl was not sent.');

                return;
            }

            try {
                $freeSwitch->api('reloadacl');
            } finally {
                $freeSwitch->disconnect();
            }
        } catch (RuntimeException $exception) {
            Log::warning('Failed to reload FreeSWITCH ACLs: '.$exception->getMessage());
        }
    }
}
