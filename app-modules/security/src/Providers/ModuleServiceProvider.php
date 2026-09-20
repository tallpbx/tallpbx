<?php

declare(strict_types=1);

namespace Modules\Security\Providers;

use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\SofiaFailedAuth;
use Illuminate\Auth\Events\Failed;
use Modules\Security\Console\Commands\SecurityApplyCommand;
use Modules\Security\Console\Commands\SecurityReconcileCommand;
use Modules\Security\Console\Commands\SecurityStatusCommand;
use Modules\Security\Console\Commands\SecurityUnbanCommand;
use Modules\Security\Console\Commands\SecurityVerifyCommand;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;
use Modules\Security\Listeners\LogFailedLoginListener;
use Modules\Security\Listeners\LogFailedSipAuthListener;
use Modules\Security\Services\SecurityBanService;
use Modules\Security\Services\SecurityExecutor;
use Modules\Security\Services\SecurityIncidentService;

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
    ];

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
            ],
            SofiaFailedAuth::class => [
                LogFailedSipAuthListener::class,
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
            SecurityReconcileCommand::class,
            SecurityStatusCommand::class,
            SecurityUnbanCommand::class,
            SecurityVerifyCommand::class,
        ];
    }
}
