<?php

declare(strict_types=1);

namespace Modules\MusicOnHold\Providers;

/**
 * Service provider for the music-on-hold module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'music-on-hold';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\MusicOnHold';
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
                'key' => 'music-on-hold',
                'label' => 'admin.music_on_hold',
                'route' => 'panel.music-on-hold.index',
                'permission' => 'music-on-hold.view',
                'icon' => 'heroicon-o-musical-note',
                'parent' => 'pbx.media',
                'order' => 40,
            ],
        ];
    }

    /**
     * Register permissions for the music-on-hold module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'music-on-hold.view' => 'View music on hold entries',
            'music-on-hold.create' => 'Create new music on hold entries',
            'music-on-hold.edit' => 'Edit existing music on hold entries',
            'music-on-hold.delete' => 'Delete music on hold entries',
        ];
    }
}
