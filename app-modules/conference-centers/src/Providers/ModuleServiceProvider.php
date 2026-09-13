<?php

declare(strict_types=1);

namespace Modules\ConferenceCenters\Providers;

use Modules\ConferenceCenters\Services\ConferenceCenterService;

/**
 * Service provider for the conference-centers module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's services.
     *
     * Tags ConferenceCenterService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(ConferenceCenterService::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'conference-centers';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\ConferenceCenters';
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
                'key' => 'conference-centers',
                'label' => 'admin.conference_centers',
                'route' => 'panel.conference-centers.index',
                'permission' => 'conference-centers.view',
                'icon' => 'heroicon-o-building-library',
                'parent' => 'pbx.media',
                'order' => 60,
            ],
        ];
    }

    /**
     * Register permissions for the conference-centers module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'conference-centers.view' => 'View conference centers',
            'conference-centers.create' => 'Create new conference centers',
            'conference-centers.edit' => 'Edit existing conference centers',
            'conference-centers.delete' => 'Delete conference centers',
        ];
    }
}
