<?php

declare(strict_types=1);

namespace Modules\ActiveCalls\Providers;

// Base class resolved via FQCN in extends clause.

/**
 * Service provider for the active-calls module.
 *
 * Registers views, routes, Livewire components, menu entries, and
 * permissions via the base ModuleServiceProvider configuration.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module name used for view and Livewire namespaces.
     */
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'active-calls';
    }

    /**
     * PHP namespace for this module's classes.
     */
    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\ActiveCalls';
    }

    /**
     * Admin sidebar menu items for active calls monitoring.
     */
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
                'key' => 'active-calls',
                'label' => 'admin.active_calls',
                'route' => 'panel.active-calls.index',
                'permission' => 'active-calls.view',
                'icon' => 'heroicon-o-phone',
                'parent' => 'pbx.monitoring',
                'order' => 20,
            ],
        ];
    }

    /**
     * Permissions for this module.
     */
    /**
     * Register permissions for the active-calls module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'active-calls.view' => 'View active calls',
            'active-calls.hangup' => 'Hang up active calls',
            'active-calls.transfer' => 'Transfer active calls',
        ];
    }
}
