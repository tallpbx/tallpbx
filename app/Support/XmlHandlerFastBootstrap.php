<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\XmlHandlerController;
use App\Http\Middleware\LogXmlHandlerTiming;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Livewire\LivewireServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots a minimal application container for the FreeSWITCH XML handler.
 *
 * A normal request registers and boots roughly 90 service providers, because
 * the panel needs views, translations, menus, commands and 60 modules. A
 * directory or dialplan lookup needs none of that, so this class builds a
 * container with a short allowlist of providers and dispatches the request
 * through the existing XmlHandlerController.
 *
 * Measured on the 1 vCPU reference host, the provider phase is 87 % of the
 * ~44 ms a lookup spends before the controller runs.
 *
 * Nothing here changes what the controller does; it changes only what exists in
 * memory when the controller runs. That is why the byte-equality test, not code
 * review, is the gate for any edit to PROVIDERS.
 */
final class XmlHandlerFastBootstrap
{
    /**
     * Providers required to render FreeSWITCH XML.
     *
     * Deliberately excludes App\Providers\ModuleServiceProvider (all 60 module
     * providers), Livewire's panel wiring, Boost, Reverb and the panel-only
     * packages. Adding an entry here costs boot time; leaving one out risks a
     * wrong document, which the byte-equality test detects.
     *
     * The module providers are not listed here: they are read at boot from
     * `freeswitch.xml_module_providers`, so this class never imports a module.
     *
     * @var list<class-string>
     */
    private const FRAMEWORK_PROVIDERS = [
        FoundationServiceProvider::class,
        DatabaseServiceProvider::class,
        // The seeded directory path resolves the encrypter (SIP password and
        // voicemail PIN handling) while rendering a user entry.
        EncryptionServiceProvider::class,
        CacheServiceProvider::class,
        RedisServiceProvider::class,
        FilesystemServiceProvider::class,
        TranslationServiceProvider::class,
        // AppServiceProvider touches the view factory while booting (shared
        // view data), so the factory must exist even though no view renders
        // on the XML path.
        ViewServiceProvider::class,
        // Bindings for TenantManager, TenantIdentityResolverInterface and the
        // XML builders live here, as does the `xml-handler` rate limiter.
        AppServiceProvider::class,
    ];

    /**
     * Providers that must be registered after the module providers.
     *
     * @var list<class-string>
     */
    private const TRAILING_PROVIDERS = [
        // Module providers register their Livewire components while booting,
        // which resolves the component finder. No component renders on the XML
        // path; the provider is here only so that registration does not fail.
        LivewireServiceProvider::class,
    ];

    /**
     * The provider classes to register, in order.
     *
     * The module providers come from configuration rather than from a list in
     * this file, so the shared kernel never imports a module. They sit between
     * the framework providers (which bind what the module providers resolve
     * while booting) and the trailing ones (which resolve what the modules
     * register).
     *
     * @return list<class-string>
     */
    private static function providerClasses(Application $app): array
    {
        /** @var list<class-string> $modules */
        $modules = array_values((array) $app->make('config')->get('freeswitch.xml_module_providers', []));

        return [...self::FRAMEWORK_PROVIDERS, ...$modules, ...self::TRAILING_PROVIDERS];
    }

    /**
     * Handle an XML handler request using the minimal container.
     *
     * The previous container and facade root are restored afterwards, because
     * this method may run inside an already-booted application (tests, CLI,
     * queue workers). Leaving a second application installed would corrupt the
     * caller's container resolution.
     */
    public static function handle(Request $request): Response
    {
        $previousApp = Container::getInstance();
        $previousFacadeApp = Facade::getFacadeApplication();

        $app = self::boot($previousApp, $request);

        try {
            return self::dispatch($app, $request);
        } finally {
            Container::setInstance($previousApp);
            Facade::setFacadeApplication($previousFacadeApp);
        }
    }

    /**
     * Build and boot the minimal application container.
     *
     * @param  Container|null  $previous  The container that was active before this call
     */
    private static function boot(?Container $previous, Request $request): Application
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
        if ($previous !== null && $previous->bound('config') && $previous->resolved('config')) {
            $app->instance('config', $previous->make('config'));
        }

        /** @var list<ServiceProvider> $booted */
        $booted = [];

        foreach (self::providerClasses($app) as $providerClass) {
            $booted[] = $app->register($providerClass);
        }

        // Reuse an already-connected database manager when one exists, so an
        // in-memory database (tests) or an open transaction stays visible.
        // This must run after provider registration: DatabaseServiceProvider
        // would otherwise rebind `db` over it. A real front controller request
        // has nothing bound and connects itself.
        if ($previous !== null && $previous->bound('db') && $previous->resolved('db')) {
            $app->instance('db', $previous->make('db'));
        }

        // Application::boot() is not called on purpose: bootstrap/app.php
        // registers a "booting" callback that registers the route service
        // provider and loads every route file, which is exactly the cost this
        // class exists to avoid. Booting the allowlisted providers directly
        // keeps the pipeline identical without loading the route table.
        foreach ($booted as $provider) {
            // Not every provider defines boot(); the base ServiceProvider has
            // no such method in this framework version. Module providers rely
            // on boot-method dependency injection, so the container must call
            // boot() rather than invoking it directly.
            if (! $provider->isDeferred() && method_exists($provider, 'boot')) {
                $app->call([$provider, 'boot']);
            }
        }

        return $app;
    }

    /**
     * Dispatch the request through the real router with the single XML route.
     *
     * Registering one route by hand (instead of loading routes/api.php) keeps
     * the real middleware pipeline — timing and the named throttle — so
     * behaviour matches the normal path byte for byte.
     *
     * @param  Application  $app  The minimal container
     * @param  Request  $request  The FreeSWITCH request
     */
    private static function dispatch(Application $app, Request $request): Response
    {
        /** @var Router $router */
        $router = $app->make(Router::class);

        // The HTTP kernel normally registers middleware aliases; a bare router
        // must define the one this route uses, so the route declaration stays
        // identical to the one in routes/api.php.
        $router->aliasMiddleware('throttle', ThrottleRequests::class);

        // Register the route at the path the request actually arrived on. The
        // front controller is mounted wherever nginx points SCRIPT_FILENAME,
        // which is not necessarily the canonical route path, and the handler
        // does not depend on the URL it was reached through.
        $router->match(
            ['get', 'post'],
            '/'.ltrim($request->path(), '/'),
            [XmlHandlerController::class, 'handle'],
        )
            ->middleware([LogXmlHandlerTiming::class, 'throttle:xml-handler'])
            ->name('api.xml-handler');

        return $router->dispatch($request);
    }
}
