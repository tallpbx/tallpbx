<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\XmlHandlerController;
use App\Http\Middleware\LogXmlHandlerTiming;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\View\ViewServiceProvider;

/**
 * Boots a minimal application container for the FreeSWITCH XML handler.
 *
 * A normal request registers and boots roughly 90 service providers, because
 * the panel needs views, translations, menus, commands and 60 modules. A
 * directory or dialplan lookup needs none of that, so this class supplies an
 * allowlist to the shared minimal bootstrap and dispatches the request
 * through the existing XmlHandlerController.
 *
 * Measured on the 1 vCPU reference host, the provider phase is 87 % of the
 * ~44 ms a lookup spends before the controller runs.
 *
 * Nothing here changes what the controller does; it changes only what exists in
 * memory when the controller runs. That is why the byte-equality test, not code
 * review, is the gate for any edit to FRAMEWORK_PROVIDERS.
 */
final class XmlHandlerFastBootstrap extends MinimalBootstrap
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
    protected static function providerClasses(): array
    {
        return [...self::FRAMEWORK_PROVIDERS, ...TelephonyWorkerProviders::modules()];
    }

    /**
     * The one route this entry serves.
     *
     * Identical to the declaration in routes/api.php: the same controller
     * action, the timing middleware and the named limiter, so the fast entry
     * and the full application answer the same lookup the same way.
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
            'methods' => ['get', 'post'],
            'controller' => XmlHandlerController::class,
            'action' => 'handle',
            'middleware' => [LogXmlHandlerTiming::class, 'throttle:xml-handler'],
            'name' => 'api.xml-handler',
        ];
    }
}
