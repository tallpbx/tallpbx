<?php

declare(strict_types=1);

namespace Modules\OperatorPanel\Providers;

// Base class resolved via FQCN in extends clause.

/**
 * Service provider for the operator-panel module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'operator-panel';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\OperatorPanel';
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
                'key' => 'operator-panel',
                'label' => 'admin.operator_panel',
                'route' => 'panel.operator-panel.index',
                'permission' => 'operator-panel.view',
                'icon' => 'heroicon-o-eye',
                'parent' => 'pbx.monitoring',
                'order' => 80,
            ],
        ];
    }

    /**
     * Register permissions for the operator-panel module.
     *
     * These permissions control access to viewing the panel and
     * controlling calls from it (originate and hangup).
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'operator-panel.view' => 'View operator panel',
            'operator-panel.originate' => 'Originate calls from the operator panel',
            'operator-panel.hangup' => 'Hang up calls from the operator panel',
        ];
    }
}
