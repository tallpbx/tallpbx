<?php

declare(strict_types=1);

namespace Modules\AccessControls\Providers;

use Modules\AccessControls\Services\AccessControlService;
use Modules\AccessControls\Services\AccessControlServiceInterface;
use Modules\AccessControls\Support\AccessControlsUninstaller;

/**
 * Service provider for the access-controls module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        AccessControlServiceInterface::class => AccessControlService::class,
    ];

    /**
     * Register the module's destructive uninstall handler.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(AccessControlsUninstaller::class, 'module.uninstallers');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'access-controls';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\AccessControls';
    }

    protected function hasTranslations(): bool
    {
        return true;
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
                'key' => 'access-controls',
                'label' => 'admin.access_controls',
                'route' => 'panel.access-controls.index',
                'permission' => 'access-controls.view',
                'icon' => 'heroicon-o-shield-check',
                'parent' => 'pbx.advanced',
                'order' => 10,
            ],
        ];
    }

    /**
     * Register permissions for the access-controls module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'access-controls.view' => 'View access control lists',
            'access-controls.create' => 'Create new access control lists',
            'access-controls.edit' => 'Edit existing access control lists',
            'access-controls.delete' => 'Delete access control lists',
        ];
    }
}
