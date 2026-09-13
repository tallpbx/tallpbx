<?php

declare(strict_types=1);

namespace Modules\RingGroups\Providers;

use Modules\RingGroups\Services\RingGroupService;
use Modules\RingGroups\Services\RingGroupServiceInterface;

/**
 * Service provider for the ring-groups module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        RingGroupServiceInterface::class => RingGroupService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags RingGroupService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(RingGroupServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'ring-groups';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\RingGroups';
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
                'key' => 'ring-groups',
                'label' => 'admin.ring_groups',
                'route' => 'panel.ring-groups.index',
                'permission' => 'ring-groups.view',
                'icon' => 'heroicon-o-phone-arrow-up-right',
                'parent' => 'pbx.routing',
                'order' => 30,
            ],
        ];
    }

    /**
     * Register permissions for the ring-groups module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'ring-groups.view' => 'View ring groups',
            'ring-groups.create' => 'Create new ring groups',
            'ring-groups.edit' => 'Edit existing ring groups',
            'ring-groups.delete' => 'Delete ring groups',
        ];
    }
}
