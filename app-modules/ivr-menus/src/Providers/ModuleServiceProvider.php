<?php

declare(strict_types=1);

namespace Modules\IvrMenus\Providers;

use Modules\IvrMenus\Services\IvrMenuService;
use Modules\IvrMenus\Services\IvrMenuServiceInterface;

/**
 * Service provider for the ivr-menus module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        IvrMenuServiceInterface::class => IvrMenuService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags IvrMenuService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(IvrMenuServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'ivr-menus';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\IvrMenus';
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
                'key' => 'ivr-menus',
                'label' => 'admin.ivr_menus',
                'route' => 'panel.ivr-menus.index',
                'permission' => 'ivr-menus.view',
                'icon' => 'heroicon-o-queue-list',
                'parent' => 'pbx.routing',
                'order' => 80,
            ],
        ];
    }

    /**
     * Register permissions for the ivr-menus module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'ivr-menus.view' => 'View IVR menus',
            'ivr-menus.create' => 'Create new IVR menus',
            'ivr-menus.edit' => 'Edit existing IVR menus',
            'ivr-menus.delete' => 'Delete IVR menus',
        ];
    }
}
