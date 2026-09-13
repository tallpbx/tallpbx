<?php

declare(strict_types=1);

namespace Modules\SipStatus\Providers;

// Base class resolved via FQCN in extends clause.

/**
 * Service provider for the sip-status module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'sip-status';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\SipStatus';
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
                'key' => 'sip-status',
                'label' => 'admin.sip_status',
                'route' => 'panel.sip-status.index',
                'permission' => 'sip-status.view',
                'icon' => 'heroicon-o-signal',
                'parent' => 'pbx.monitoring',
                'order' => 50,
            ],
        ];
    }

    /**
     * Register permissions for the sip-status module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'sip-status.view' => 'View SIP status',
        ];
    }
}
