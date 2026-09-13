<?php

declare(strict_types=1);

namespace Modules\CallRecordings\Providers;

/**
 * Service provider for the call-recordings module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'call-recordings';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\CallRecordings';
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
                'key' => 'call-recordings',
                'label' => 'admin.call_recordings',
                'route' => 'panel.call-recordings.index',
                'permission' => 'call-recordings.view',
                'icon' => 'heroicon-o-microphone',
                'parent' => 'pbx.monitoring',
                'order' => 40,
            ],
        ];
    }

    /**
     * Register permissions for the call-recordings module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'call-recordings.view' => 'View call recordings',
            'call-recordings.delete' => 'Delete call recordings',
        ];
    }
}
