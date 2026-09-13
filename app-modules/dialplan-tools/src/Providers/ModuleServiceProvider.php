<?php

declare(strict_types=1);

namespace Modules\DialplanTools\Providers;

/**
 * Service provider for the dialplan-tools module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'dialplan-tools';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\DialplanTools';
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
                'key' => 'admin.dialplan-tools',
                'label' => 'admin.dialplan_tools',
                'route' => 'panel.dialplan-tools.index',
                'permission' => 'dialplan-tools.view',
                'icon' => 'heroicon-o-wrench',
                'parent' => 'pbx.advanced',
                'guard' => 'admin',
                'order' => 90,
            ],
        ];
    }

    /**
     * Register permissions for the dialplan-tools module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'dialplan-tools.view' => 'View dialplan tools',
        ];
    }
}
