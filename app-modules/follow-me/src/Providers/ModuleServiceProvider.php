<?php

declare(strict_types=1);

namespace Modules\FollowMe\Providers;

use Modules\FollowMe\Services\FollowMeService;

/**
 * Service provider for the follow-me module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's services.
     *
     * Tags FollowMeService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(FollowMeService::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'follow-me';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\FollowMe';
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
                'key' => 'follow-me',
                'label' => 'admin.follow_me',
                'route' => 'panel.follow-me.index',
                'permission' => 'follow-me.view',
                'icon' => 'heroicon-o-phone-arrow-up-right',
                'parent' => 'pbx.routing',
                'order' => 20,
            ],
        ];
    }

    /**
     * Register permissions for the follow-me module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'follow-me.view' => 'View follow-me records',
            'follow-me.create' => 'Create new follow-me records',
            'follow-me.edit' => 'Edit existing follow-me records',
            'follow-me.delete' => 'Delete follow-me records',
        ];
    }
}
