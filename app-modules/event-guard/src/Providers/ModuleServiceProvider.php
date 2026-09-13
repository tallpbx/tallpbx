<?php

declare(strict_types=1);

namespace Modules\EventGuard\Providers;

/**
 * Service provider for the event-guard module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'event-guard';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\EventGuard';
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
            // Admin sidebar — visible to users with the 'admin' guard
            [
                'key' => 'admin.event-guard',
                'label' => 'admin.event_guard',
                'route' => 'panel.event-guard.index',
                'permission' => 'event-guard.view',
                'icon' => 'heroicon-o-shield-exclamation',
                'parent' => 'pbx.advanced',
                'guard' => 'admin',
                'order' => 40,
            ],
        ];
    }

    /**
     * Register permissions for the event-guard module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'event-guard.view' => 'View event guard settings',
        ];
    }
}
