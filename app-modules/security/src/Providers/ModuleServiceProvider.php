<?php

declare(strict_types=1);

namespace Modules\Security\Providers;

use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\SipScannerDetected;
use App\Events\FreeSwitch\SofiaFailedAuth;
use App\Events\FreeSwitch\XmlHandlerFailedAuth;
use Illuminate\Auth\Events\Failed;
use Modules\Security\Console\Commands\SecurityApplyCommand;
use Modules\Security\Console\Commands\SecurityObserveCommand;
use Modules\Security\Console\Commands\SecurityReconcileCommand;
use Modules\Security\Console\Commands\SecurityStatusCommand;
use Modules\Security\Console\Commands\SecuritySyncGeoIpCommand;
use Modules\Security\Console\Commands\SecuritySyncThreatFeedsCommand;
use Modules\Security\Console\Commands\SecurityUnbanCommand;
use Modules\Security\Console\Commands\SecurityVerifyCommand;
use Modules\Security\Contracts\FirewallApplierInterface;
use Modules\Security\Contracts\GeoBlockingProviderInterface;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;
use Modules\Security\Listeners\LogFailedLoginListener;
use Modules\Security\Listeners\LogFailedSipAuthListener;
use Modules\Security\Listeners\LogFailedXmlHandlerAuthListener;
use Modules\Security\Listeners\LogSipScannerListener;
use Modules\Security\Services\Bans\SecurityBanService;
use Modules\Security\Services\Bans\SecurityIncidentService;
use Modules\Security\Services\Detection\SipScannerDialplanContributor;
use Modules\Security\Services\Firewall\FirewallApplier;
use Modules\Security\Services\Firewall\SecurityExecutor;
use Modules\Security\Services\Geo\GeoBlockingService;
use Modules\Security\Services\Geo\IpdenyProvider;
use Modules\Security\Services\ThreatFeeds\ThreatFeedIngestionService;
use Modules\Security\Services\ThreatFeeds\ThreatFeedManager;
use Modules\Security\Services\ThreatFeeds\VoipblFeedProvider;

/**
 * Service provider for the security module.
 *
 * Registers host firewall management, trusted and blocked IP lists,
 * and automatic intrusion protection in the unified admin panel.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'security';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\\Security';
    }

    /**
     * Register sidebar navigation menu items.
     *
     * Places the Security Center as a primary server-wide item on the main panel menu.
     * Visibility is governed by the 'security.view' permission.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            [
                'key' => 'security',
                'label' => 'admin.security',
                'route' => 'panel.security.index',
                'permission' => 'security.view',
                'icon' => 'heroicon-o-shield-check',
                'guard' => 'admin',
                // Group under PBX → Advanced: nav parent must mirror the
                // manifest category "advanced" (AGENTS.md nav parity invariant).
                'parent' => 'pbx.advanced',
                'order' => 39,
            ],
        ];
    }

    /**
     * All container singletons and bindings registered by this module.
     *
     * @var array<class-string, class-string>
     */
    public $bindings = [
        SecurityIncidentServiceInterface::class => SecurityIncidentService::class,
        SecurityBanServiceInterface::class => SecurityBanService::class,
        SecurityExecutorInterface::class => SecurityExecutor::class,
        GeoBlockingProviderInterface::class => IpdenyProvider::class,
        FirewallApplierInterface::class => FirewallApplier::class,
    ];

    /**
     * Container singletons registered by this module.
     *
     * The threat feed manager, its drivers, and the ingestion pipeline are
     * singletons so a scheduled run and a panel-triggered sync share one
     * registry of providers.
     *
     * @var array<class-string, class-string>
     */
    public $singletons = [
        ThreatFeedManager::class => ThreatFeedManager::class,
        VoipblFeedProvider::class => VoipblFeedProvider::class,
        ThreatFeedIngestionService::class => ThreatFeedIngestionService::class,
        IpdenyProvider::class => IpdenyProvider::class,
        GeoBlockingService::class => GeoBlockingService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags the SIP scanner detection service as a dialplan XML contributor
     * so it renders into every public context ahead of the call-block and
     * routing rules.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(SipScannerDialplanContributor::class, 'dialplan.xml');
    }

    /**
     * Register permissions for the security module.
     *
     * These permissions control access to viewing the security dashboard,
     * modifying firewall rules, updating trusted/blocked lists, and tuning protection thresholds.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'security.view' => 'View security dashboard, firewall rules, and blocked IP lists',
            'security.edit' => 'Manage firewall rules, trusted/blocked IP lists, and protection settings',
            'security.threat-feeds.manage' => 'Manage public threat feed sources and their kernel block lists',
            'security.geo-blocking.manage' => 'Manage Geo-Blocking country perimeter rules and IP zones',
        ];
    }

    /**
     * Register event listeners for the security module.
     *
     * Listens for authentication failure events to track brute-force attacks in-process.
     *
     * @return array<class-string, array<int, class-string>>
     */
    protected function listeners(): array
    {
        return [
            Failed::class => [
                LogFailedLoginListener::class,
            ],
            CustomEvent::class => [
                LogFailedSipAuthListener::class,
                // FreeSWITCH ESL may deliver the scanner detection as the
                // generic CustomEvent when the typed mapping is unavailable;
                // the listener's subclass guard keeps both routes safe.
                LogSipScannerListener::class,
            ],
            SofiaFailedAuth::class => [
                LogFailedSipAuthListener::class,
            ],
            XmlHandlerFailedAuth::class => [
                LogFailedXmlHandlerAuthListener::class,
            ],
            SipScannerDetected::class => [
                LogSipScannerListener::class,
            ],
        ];
    }

    /**
     * Register Artisan console commands for the security module.
     *
     * @return array<int, class-string>
     */
    protected function consoleCommands(): array
    {
        return [
            SecurityApplyCommand::class,
            SecurityObserveCommand::class,
            SecurityReconcileCommand::class,
            SecurityStatusCommand::class,
            SecuritySyncGeoIpCommand::class,
            SecuritySyncThreatFeedsCommand::class,
            SecurityUnbanCommand::class,
            SecurityVerifyCommand::class,
        ];
    }
}
