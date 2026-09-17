<?php

declare(strict_types=1);

namespace Modules\Security\Providers;

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
     * Places the Security Center in the admin panel under PBX -> Advanced.
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
                'parent' => 'pbx.advanced',
                'guard' => 'admin',
                'order' => 15,
            ],
        ];
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
        ];
    }
}
