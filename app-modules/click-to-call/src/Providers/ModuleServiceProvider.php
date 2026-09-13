<?php

declare(strict_types=1);

namespace Modules\ClickToCall\Providers;

/**
 * Service provider for the click-to-call module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'click-to-call';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\ClickToCall';
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
                'key' => 'admin.click-to-call',
                'label' => 'admin.click_to_call',
                'route' => 'panel.click-to-call.index',
                'permission' => 'click-to-call.view',
                'icon' => 'heroicon-o-cursor-arrow-rays',
                'parent' => 'pbx.advanced',
                'guard' => 'admin',
                'order' => 80,
            ],
        ];
    }

    /**
     * Register permissions for the click-to-call module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'click-to-call.view' => 'View click to call',
        ];
    }
}
