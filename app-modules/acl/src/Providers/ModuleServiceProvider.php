<?php

declare(strict_types=1);

namespace Modules\Acl\Providers;

use Modules\Acl\Services\AccessControlService;
use Modules\Acl\Services\AccessControlServiceInterface;
use Modules\Acl\Support\AclUninstaller;

/**
 * Service provider for the acl module.
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

        $this->app->tag(AclUninstaller::class, 'module.uninstallers');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'acl';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Acl';
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
                'key' => 'acl',
                'label' => 'admin.acl',
                'route' => 'panel.acl.index',
                'permission' => 'acl.view',
                'icon' => 'heroicon-o-shield-check',
                'parent' => 'pbx.advanced',
                'order' => 10,
            ],
        ];
    }

    /**
     * Register permissions for the acl module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'acl.view' => 'View access control lists',
            'acl.create' => 'Create new access control lists',
            'acl.edit' => 'Edit existing access control lists',
            'acl.delete' => 'Delete access control lists',
        ];
    }
}
