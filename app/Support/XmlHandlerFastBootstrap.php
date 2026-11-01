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
use Modules\NumberTranslations\Providers\ModuleServiceProvider;
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
     * providers), Livewire, Boost, Reverb and the panel-only packages. Adding
     * an entry here costs boot time; leaving one out risks a wrong document,
     * which the byte-equality test detects.
     *
     * @var list<class-string>
     */
    private const PROVIDERS = [
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
        // DialplanXmlBuilder is a constructor dependency of the controller, so
        // its service graph is built even for directory lookups. This module
        // binds NumberTranslationServiceInterface, which that graph needs.
        ModuleServiceProvider::class,
        // SofiaConfigXmlBuilder (the `configuration` section) needs a SIP
        // profile service and a media storage service; both are module
        // bindings, and the builder is constructed for every request.
        \Modules\SipProfiles\Providers\ModuleServiceProvider::class,
        \Modules\FileStores\Providers\ModuleServiceProvider::class,
        // SipProfileService resolves the gateway service while rendering
        // sofia configuration.
        \Modules\Gateways\Providers\ModuleServiceProvider::class,
        // Dialplan contributors are registered as a tagged collection during
        // provider registration. Without these, dialplan documents render
        // without their routing rules and nothing reports an error.
        // Contributors resolve the extension service to expand targets.
        \Modules\Extensions\Providers\ModuleServiceProvider::class,
        \Modules\Bridges\Providers\ModuleServiceProvider::class,
        \Modules\CallBlocks\Providers\ModuleServiceProvider::class,
        \Modules\CallCenters\Providers\ModuleServiceProvider::class,
        \Modules\CallFlows\Providers\ModuleServiceProvider::class,
        \Modules\CallForwards\Providers\ModuleServiceProvider::class,
        \Modules\ConferenceCenters\Providers\ModuleServiceProvider::class,
        \Modules\Conferences\Providers\ModuleServiceProvider::class,
        \Modules\Emergency\Providers\ModuleServiceProvider::class,
        \Modules\FeatureCodes\Providers\ModuleServiceProvider::class,
        \Modules\FollowMe\Providers\ModuleServiceProvider::class,
        \Modules\HotDesking\Providers\ModuleServiceProvider::class,
        \Modules\InboundRoutes\Providers\ModuleServiceProvider::class,
        \Modules\IvrMenus\Providers\ModuleServiceProvider::class,
        \Modules\OutboundRoutes\Providers\ModuleServiceProvider::class,
        \Modules\PinNumbers\Providers\ModuleServiceProvider::class,
        \Modules\RingGroups\Providers\ModuleServiceProvider::class,
        // Security contributes the dialplan guard extensions; omitting it
        // would serve routing rules without their guard conditions.
        \Modules\Security\Providers\ModuleServiceProvider::class,
        \Modules\TimeConditions\Providers\ModuleServiceProvider::class,
        \Modules\Voicemails\Providers\ModuleServiceProvider::class,
        // Module providers register their Livewire components while booting,
        // which resolves the component finder. No component renders on the XML
        // path; the provider is here only so that registration does not fail.
        LivewireServiceProvider::class,
    ];

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
        /** @var Application $app */
        $app = require base_path('bootstrap/app.php');

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

        foreach (self::PROVIDERS as $providerClass) {
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

        $router->match(
            ['get', 'post'],
            (string) config('freeswitch.xml_handler.path', '/api/v1/xml-handler'),
            [XmlHandlerController::class, 'handle'],
        )
            ->middleware([LogXmlHandlerTiming::class, 'throttle:xml-handler'])
            ->name('api.xml-handler');

        return $router->dispatch($request);
    }
}
