<?php

declare(strict_types=1);

namespace Modules\Recordings\Providers;

/**
 * Service provider for the recordings module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'recordings';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Recordings';
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
                'key' => 'recordings',
                'label' => 'admin.recordings',
                'route' => 'panel.recordings.index',
                'permission' => 'recordings.view',
                'icon' => 'heroicon-o-musical-note',
                'parent' => 'pbx.media',
                'order' => 30,
            ],
        ];
    }

    /**
     * Register permissions for the recordings module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'recordings.view' => 'View recordings',
            'recordings.create' => 'Create new recordings',
            'recordings.edit' => 'Edit existing recordings',
            'recordings.delete' => 'Delete recordings',
        ];
    }
}
