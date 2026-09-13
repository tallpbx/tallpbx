<?php

declare(strict_types=1);

namespace Modules\Registrations\Providers;

// Base class resolved via FQCN in extends clause.

/**
 * Service provider for the registrations module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'registrations';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Registrations';
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
                'key' => 'registrations',
                'label' => 'admin.registrations',
                'route' => 'panel.registrations.index',
                'permission' => 'registrations.view',
                'icon' => 'heroicon-o-server',
                'parent' => 'pbx.monitoring',
                'order' => 40,
            ],
        ];
    }

    /**
     * Register permissions for the registrations module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'registrations.view' => 'View SIP registrations',
        ];
    }
}
