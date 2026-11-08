<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\CdrIngestionController;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Redis\RedisServiceProvider;

/**
 * Boots a minimal application container for the CDR ingestion endpoint.
 *
 * mod_json_cdr posts one JSON document per call leg, and the endpoint does
 * three things: verify a shared token, append the document to the Redis
 * stream, and answer an empty 200. The full application spends most of that
 * request booting ~90 providers and the whole route table, which is why CDR
 * posts starved behind XML lookups on a shared PHP-FPM pool.
 *
 * The allowlist below is the shared core and nothing else. There is no
 * database work, no encrypted cast and no view on this path, so the
 * Database, Encryption and View providers stay out; no module provider is
 * registered either, because the request touches no module code. That is why
 * the controller returns JsonResponse directly: the response() helper would
 * pull the view factory back in.
 *
 * Nothing here changes what the controller does; the write-equivalence test,
 * not code review, is the gate for any edit to PROVIDERS.
 */
final class CdrFastBootstrap extends MinimalBootstrap
{
    /**
     * Providers required to verify the token and append to the stream.
     *
     * FoundationServiceProvider carries the router and the request lifecycle;
     * CacheServiceProvider backs the named `cdr-ingestion` rate limiter;
     * RedisServiceProvider resolves the isolated stream connection on
     * database 3; FilesystemServiceProvider is kept for parity with the
     * proven XML allowlist and is cheap; AppServiceProvider registers the
     * limiter and the shared bindings.
     *
     * @var list<class-string>
     */
    private const PROVIDERS = [
        FoundationServiceProvider::class,
        CacheServiceProvider::class,
        RedisServiceProvider::class,
        FilesystemServiceProvider::class,
        AppServiceProvider::class,
    ];

    /**
     * The provider classes to register, in order.
     *
     * @return list<class-string>
     */
    protected static function providerClasses(): array
    {
        return self::PROVIDERS;
    }

    /**
     * The one route this entry serves.
     *
     * Identical to the declaration in routes/api.php: the same controller
     * action, the same named per-IP limiter and the same route name, so the
     * fast entry and the full application cannot answer differently.
     *
     * @return array{
     *     methods: list<string>,
     *     controller: class-string,
     *     action: string,
     *     middleware: list<string>,
     *     name: string
     * }
     */
    protected static function route(): array
    {
        return [
            'methods' => ['post'],
            'controller' => CdrIngestionController::class,
            'action' => 'store',
            'middleware' => ['throttle:cdr-ingestion'],
            'name' => 'api.v1.cdr.store',
        ];
    }
}
