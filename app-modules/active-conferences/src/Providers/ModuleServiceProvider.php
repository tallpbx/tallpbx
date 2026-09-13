<?php

declare(strict_types=1);

namespace Modules\ActiveConferences\Providers;

// Base class resolved via FQCN in extends clause.

/**
 * Service provider for the active-conferences module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'active-conferences';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\ActiveConferences';
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
                'key' => 'active-conferences',
                'label' => 'admin.active_conferences',
                'route' => 'panel.active-conferences.index',
                'permission' => 'active-conferences.view',
                'icon' => 'heroicon-o-users',
                'parent' => 'pbx.monitoring',
                'order' => 30,
            ],
        ];
    }

    /**
     * Register permissions for the active-conferences module.
     *
     * These permissions control access to viewing conferences and
     * controlling their members (mute/unmute and kick).
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'active-conferences.view' => 'View active conferences',
            'active-conferences.mute' => 'Mute conference members',
            'active-conferences.kick' => 'Kick conference members',
        ];
    }
}
