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
use Illuminate\View\ViewServiceProvider;
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
     * providers), the translation service, Livewire's panel wiring, Boost,
     * Reverb and the panel-only packages. The module providers boot through
     * the shared slim gate (TelephonyWorkerProviders::isSlimBoot()), so on
     * this path they register no views, translations or Livewire components.
     * Adding an entry here costs boot time; leaving one out risks a wrong
     * document, which the byte-equality test detects — that is how the
     * translation and Livewire trims below were verified, and how the view
     * service was proven to be required despite rendering no views.
     *
     * The module providers are not listed here: they are read at boot through
     * TelephonyWorkerProviders from `freeswitch.xml_module_providers`, the
     * same list the persistent worker registers, so this class never imports
     * a module and the two renderers can never load different sets.
     *
     * @var list<class-string>
     */
    private const FRAMEWORK_PROVIDERS = [
        FoundationServiceProvider::class,
        DatabaseServiceProvider::class,
        // Stays: the seeded directory path resolves the encrypter while
        // rendering a user entry, because SIP auth passwords are stored with
        // an encrypted model cast.
        EncryptionServiceProvider::class,
        CacheServiceProvider::class,
        RedisServiceProvider::class,
        FilesystemServiceProvider::class,
        // Stays even though no view renders here: the response() helper
        // builds the ResponseFactory with the view factory injected, so every
        // plain XML response resolves this service. Dropping it fails every
        // request — caught by the byte-equality test before release.
        ViewServiceProvider::class,
        // Bindings for TenantManager, TenantIdentityResolverInterface and the
        // XML builders live here, as does the `xml-handler` rate limiter.
        AppServiceProvider::class,
    ];

    /**
     * The provider classes to register, in order.
     *
     * The module providers come from TelephonyWorkerProviders rather than
     * from a list in this file, so the shared kernel never imports a module
     * and both telephony renderers register the same set. They follow the
     * framework providers, which bind what the module providers resolve while
     * booting.
     *
     * @return list<class-string>
     */
    private static function providerClasses(): array
    {
        return [...self::FRAMEWORK_PROVIDERS, ...TelephonyWorkerProviders::modules()];
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
        $previousRole = getenv('TALLPBX_WORKER_ROLE');

        // The one shared slim-boot gate both telephony renderers use: the
        // persistent worker carries TALLPBX_WORKER_ROLE=telephony in its
        // systemd unit, and this nested bootstrap sets the flag around its
        // own boot so module providers skip the panel stack through the same
        // code path. The flag is restored afterwards — a PHP-FPM worker also
        // serves later panel requests, and a leaked role would boot those
        // without their modules.
        putenv('TALLPBX_WORKER_ROLE=telephony');
        $_ENV['TALLPBX_WORKER_ROLE'] = 'telephony';
        $_SERVER['TALLPBX_WORKER_ROLE'] = 'telephony';

        try {
            $app = self::boot($previousApp, $request);

            return self::dispatch($app, $request);
        } finally {
            // Restoring here (including when boot() itself throws) keeps the
            // shim's fail-open contract: index.php must start from a clean
            // container and a clean role.
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

        foreach (self::providerClasses() as $providerClass) {
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
