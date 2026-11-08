<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots a short-lived minimal application container for one hot FreeSWITCH path.
 *
 * A normal request registers and boots roughly 90 service providers, because
 * the panel needs views, translations, menus, commands and 60 modules. The
 * XML handler and the CDR ingestion endpoint need a fraction of that, and
 * this base class holds the machinery both fast entries share: save and
 * restore the caller's container and facade root, build a container from an
 * entry-specific provider allowlist, and dispatch exactly one route through
 * the real router.
 *
 * Subclasses only decide which providers exist and which route they serve.
 * Nothing here changes what a controller does; it changes only what exists
 * in memory when the controller runs. That is why each entry's byte-equality
 * test, not code review, is the gate for any edit to its allowlist.
 */
abstract class MinimalBootstrap
{
    /**
     * The provider classes this entry registers, in order.
     *
     * Every entry owns its list because a missing provider is a wrong
     * answer (caught by that entry's equivalence test) and an extra provider
     * is pure boot cost this class exists to remove.
     *
     * @return list<class-string>
     */
    abstract protected static function providerClasses(): array;

    /**
     * Describe the single route this entry serves.
     *
     * The shape mirrors the route declaration in routes/api.php so the fast
     * entry and the full application answer the same request the same way.
     *
     * @return array{
     *     methods: list<string>,
     *     controller: class-string,
     *     action: string,
     *     middleware: list<string>,
     *     name: string
     * }
     */
    abstract protected static function route(): array;

    /**
     * Handle one request using the minimal container.
     *
     * The previous container, facade root and telephony role flag are
     * restored afterwards, including when booting throws, because this
     * method may run inside an already-booted application (tests, CLI, queue
     * workers). That restoration is also what makes the front-controller
     * shims safe: a failure falls back to index.php with a clean container.
     */
    final public static function handle(Request $request): Response
    {
        $previousApp = Container::getInstance();
        $previousFacadeApp = Facade::getFacadeApplication();
        $previousRole = getenv('TALLPBX_WORKER_ROLE');

        // The one shared slim-boot gate both telephony paths use: module
        // providers skip the panel stack while this flag is set. The CDR
        // entry registers no module providers, so for it this is a no-op;
        // it stays here so both entries boot through the same code path.
        putenv('TALLPBX_WORKER_ROLE=telephony');
        $_ENV['TALLPBX_WORKER_ROLE'] = 'telephony';
        $_SERVER['TALLPBX_WORKER_ROLE'] = 'telephony';

        try {
            $app = static::boot($previousApp, $request);

            return static::dispatch($app, $request);
        } finally {
            // Restoring here (including when boot() itself throws) keeps the
            // fail-open contract: index.php must start from a clean container
            // and a clean role.
            Container::setInstance($previousApp);
            Facade::setFacadeApplication($previousFacadeApp);

            if ($previousRole === false) {
                putenv('TALLPBX_WORKER_ROLE');
                unset($_ENV['TALLPBX_WORKER_ROLE'], $_SERVER['TALLPBX_WORKER_ROLE']);
            } else {
                putenv("TALLPBX_WORKER_ROLE={$previousRole}");
                $_ENV['TALLPBX_WORKER_ROLE'] = $previousRole;
                $_SERVER['TALLPBX_WORKER_ROLE'] = $previousRole;
            }
        }
    }

    /**
     * Build and boot the minimal application container.
     *
     * @param  Container|null  $previous  The container active before this call
     */
    protected static function boot(?Container $previous, Request $request): Application
    {
        // A path relative to this file, not base_path(): helpers that resolve
        // the application cannot work before the application is created, which
        // is exactly the state a real front controller starts in.
        /** @var Application $app */
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';

        // The HTTP kernel binds the request before providers boot; the URL
        // generator and view sharing resolve it lazily, so it must exist first.
        $app->instance('request', $request);

        // Environment and configuration only. HandleExceptions is deliberately
        // skipped: it installs global error and exception handlers, which a
        // nested bootstrap must not do to its caller.
        (new LoadEnvironmentVariables)->bootstrap($app);
        (new LoadConfiguration)->bootstrap($app);

        // Livewire resolves a facade while registering, so facades must be set
        // up before any provider is registered.
        (new RegisterFacades)->bootstrap($app);

        // Reuse an already-resolved configuration repository when one exists.
        // A nested bootstrap (tests, CLI) must see the caller's runtime
        // overrides; a real front controller request has none and keeps the
        // configuration loaded from disk above.
        self::reuseResolved($app, $previous, 'config');

        /** @var list<ServiceProvider> $booted */
        $booted = [];

        foreach (static::providerClasses() as $providerClass) {
            $booted[] = $app->register($providerClass);
        }

        // Reuse an already-connected database manager and Redis manager when
        // the caller has them, so an in-memory database (tests), an open
        // transaction, or an injected stream connection stays visible. This
        // must run after provider registration: the providers would otherwise
        // rebind over the caller's objects. A real front controller request
        // has nothing bound and connects itself.
        self::reuseResolved($app, $previous, 'db');
        self::reuseResolved($app, $previous, 'redis');

        // Application::boot() is not called on purpose: bootstrap/app.php
        // registers a "booting" callback that registers the route service
        // provider and loads every route file, which is exactly the cost this
        // class exists to avoid. Booting the allowlisted providers directly
        // keeps the pipeline identical without loading the route table.
        foreach ($booted as $provider) {
            // Not every provider defines boot(); the base ServiceProvider has
            // no such method in this framework version. Providers rely on
            // boot-method dependency injection, so the container must call
            // boot() rather than invoking it directly.
            if (! $provider->isDeferred() && method_exists($provider, 'boot')) {
                $app->call([$provider, 'boot']);
            }
        }

        return $app;
    }

    /**
     * Dispatch the request through the real router with this entry's route.
     *
     * Registering one route by hand (instead of loading routes/api.php) keeps
     * the real middleware pipeline so behaviour matches the normal path byte
     * for byte. The route is registered at the path the request actually
     * arrived on: the front controller is mounted wherever nginx points
     * SCRIPT_FILENAME, and the handler does not depend on the URL it was
     * reached through.
     *
     * @param  Application  $app  The minimal container
     * @param  Request  $request  The FreeSWITCH request
     */
    protected static function dispatch(Application $app, Request $request): Response
    {
        /** @var Router $router */
        $router = $app->make(Router::class);

        // The HTTP kernel normally registers middleware aliases; a bare router
        // must define the one these routes use, so the route declaration stays
        // identical to the one in routes/api.php.
        $router->aliasMiddleware('throttle', ThrottleRequests::class);

        $route = static::route();

        $router->match(
            $route['methods'],
            '/'.ltrim($request->path(), '/'),
            [$route['controller'], $route['action']],
        )
            ->middleware($route['middleware'])
            ->name($route['name']);

        return $router->dispatch($request);
    }

    /**
     * Carry one of the caller's resolved bindings into the minimal container.
     *
     * Only resolved bindings are copied: an unresolved binding would make
     * this container resolve it from its own providers, which is exactly what
     * a standalone front controller request does.
     */
    private static function reuseResolved(Application $app, ?Container $previous, string $abstract): void
    {
        if ($previous === null || ! $previous->bound($abstract) || ! $previous->resolved($abstract)) {
            return;
        }

        $app->instance($abstract, $previous->make($abstract));
    }
}
