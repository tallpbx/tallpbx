<?php

declare(strict_types=1);

namespace Modules\CallCenterActive\Providers;

// Base class resolved via FQCN in extends clause.

/**
 * Service provider for the call-center-active module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'call-center-active';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\CallCenterActive';
    }

    /**
     * Register sidebar navigation menu items.
     *
     * Items with guard 'admin' appear in the admin sidebar.
     * Items with guard 'web' appear in the client/tenant sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            // Unified panel sidebar — visible to both admin and tenant users.
            // Permission gating via MenuService::cannotView() controls visibility.
            [
                'key' => 'call-center-active',
                'label' => 'admin.call_center_active',
                'route' => 'panel.call-center-active.index',
                'permission' => 'call-center-active.view',
                'icon' => 'heroicon-o-eye',
                'parent' => 'pbx.monitoring',
                'order' => 70,
            ],
        ];
    }

    /**
     * Register permissions for the call-center-active module.
     *
     * These permissions control access to viewing call center queues
     * and controlling agents (pause, resume, logout).
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'call-center-active.view' => 'View call center active',
            'call-center-active.control' => 'Control call center agents',
        ];
    }
}
