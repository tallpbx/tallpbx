<?php

declare(strict_types=1);

namespace Modules\Conferences\Providers;

use Modules\Conferences\Services\ConferenceService;

/**
 * Service provider for the conferences module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's services.
     *
     * Tags ConferenceService as a dialplan XML contributor so
     * conference room extensions are included in the dialplan
     * served to FreeSWITCH.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(ConferenceService::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'conferences';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Conferences';
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
                'key' => 'conferences',
                'label' => 'admin.conferences',
                'route' => 'panel.conferences.index',
                'permission' => 'conferences.view',
                'icon' => 'heroicon-o-video-camera',
                'parent' => 'pbx.media',
                'order' => 50,
            ],
        ];
    }

    /**
     * Register permissions for the conferences module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'conferences.view' => 'View conferences',
            'conferences.create' => 'Create new conferences',
            'conferences.edit' => 'Edit existing conferences',
            'conferences.delete' => 'Delete conferences',
        ];
    }
}
