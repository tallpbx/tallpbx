<?php

declare(strict_types=1);

namespace Modules\Admin\Providers;

use App\Services\MenuService;
use App\Services\PermissionService;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Service provider for the admin module.
 *
 * Registers views, migrations, Livewire components, routes,
 * menus, and permissions for this module.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Register the admin module's services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap the admin module's services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'admin');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'admin');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');

        $this->registerLivewireComponents();
        $this->registerMenus();
        $this->registerPermissions();
    }

    /**
     * Register Livewire MFC components for this module.
     */
    private function registerLivewireComponents(): void
    {
        Livewire::addNamespace('admin',
            classNamespace: 'Modules\\Admin\\Livewire',
            viewPath: __DIR__.'/../../resources/views',
            classPath: __DIR__.'/../../src/Livewire',
            classViewPath: __DIR__.'/../../resources/views',
        );
    }

    /**
     * Register admin and portal sidebar menus.
     */
    private function registerMenus(): void
    {
        $menu = app(MenuService::class);

        $menu->register('admin', [
            [
                'key' => 'dashboard',
                'label' => 'admin.dashboard',
                'route' => 'panel.dashboard',
                'icon' => 'heroicon-o-home',
                'order' => 10,
            ],
            [
                'key' => 'users',
                'label' => 'admin.users',
                'route' => 'panel.users.index',
                'permission' => 'admin.users.view',
                'icon' => 'heroicon-o-users',
                'order' => 20,
            ],
            [
                'key' => 'admins',
                'label' => 'admin.administrators',
                'route' => 'panel.admins.index',
                'permission' => 'admin.users.view',
                'icon' => 'heroicon-o-shield-check',
                'order' => 21,
            ],
            [
                'key' => 'tenants',
                'label' => 'admin.tenants',
                'route' => 'panel.tenants.index',
                'permission' => 'admin.tenants.view',
                'icon' => 'heroicon-o-building-office-2',
                'order' => 30,
            ],
            [
                'key' => 'tenant-domains',
                'label' => 'admin.domains',
                'route' => 'panel.tenant-domains.index',
                'permission' => 'admin.tenant-domains.view',
                'icon' => 'heroicon-o-globe-alt',
                'order' => 32,
            ],
            [
                'key' => 'groups',
                'label' => 'admin.groups',
                'route' => 'panel.groups.index',
                'permission' => 'admin.groups.view',
                'icon' => 'heroicon-o-user-group',
                'order' => 35,
            ],
            [
                'key' => 'permissions',
                'label' => 'admin.permissions',
                'route' => 'panel.permissions.index',
                'permission' => 'admin.permissions.view',
                'icon' => 'heroicon-o-shield-check',
                'order' => 36,
            ],
            [
                'key' => 'notifications',
                'label' => 'admin.notifications',
                'route' => 'panel.notifications.index',
                'permission' => 'admin.notifications.view',
                'icon' => 'heroicon-o-bell',
                'order' => 37,
            ],
            [
                'key' => 'monitoring',
                'label' => 'admin.monitoring',
                'route' => 'panel.monitoring',
                'permission' => 'admin.monitoring.view',
                'icon' => 'heroicon-o-chart-bar',
                'order' => 38,
            ],
            [
                'key' => 'backups',
                'label' => 'admin.backups',
                'route' => 'panel.backups.index',
                'permission' => 'backups.view',
                'icon' => 'heroicon-o-archive-box-arrow-down',
                'parent' => 'pbx.advanced',
                'order' => 90,
            ],
            [
                'key' => 'pbx',
                'label' => 'admin.pbx',
                'icon' => 'heroicon-o-server-stack',
                'flat_children' => true,
                'order' => 40,
            ],
            [
                'key' => 'pbx.accounts',
                'label' => 'admin.pbx_accounts',
                'icon' => 'heroicon-o-user-group',
                'parent' => 'pbx',
                'order' => 41,
            ],
            [
                'key' => 'pbx.connectivity',
                'label' => 'admin.pbx_connectivity',
                'icon' => 'heroicon-o-arrows-right-left',
                'parent' => 'pbx',
                'order' => 42,
            ],
            [
                'key' => 'pbx.routing',
                'label' => 'admin.pbx_routing',
                'icon' => 'heroicon-o-arrow-uturn-right',
                'parent' => 'pbx',
                'order' => 43,
            ],
            [
                'key' => 'pbx.features',
                'label' => 'admin.pbx_features',
                'icon' => 'heroicon-o-phone',
                'parent' => 'pbx',
                'order' => 44,
            ],
            [
                'key' => 'pbx.media',
                'label' => 'admin.pbx_media',
                'icon' => 'heroicon-o-play-circle',
                'parent' => 'pbx',
                'order' => 45,
            ],
            [
                'key' => 'pbx.advanced',
                'label' => 'admin.pbx_advanced',
                'icon' => 'heroicon-o-cog-6-tooth',
                'parent' => 'pbx',
                'order' => 48,
            ],
            [
                'key' => 'git-update',
                'label' => 'admin.git_update',
                'route' => 'panel.git-update',
                'permission' => 'admin.git-update.view',
                'icon' => 'heroicon-o-arrow-down-circle',
                'order' => 50,
            ],
            [
                'key' => 'pbx.monitoring',
                'label' => 'admin.pbx_monitoring',
                'icon' => 'heroicon-o-chart-bar',
                'parent' => 'pbx',
                'order' => 49,
            ],
        ]);
    }

    private function registerPermissions(): void
    {
        $perm = app(PermissionService::class);

        $perm->register('admin', [
            'admin.dashboard.view' => 'View the admin dashboard',
            'admin.users.view' => 'View admin users',
            'admin.users.create' => 'Create new admin users',
            'admin.users.update' => 'Edit existing admin users',
            'admin.users.delete' => 'Delete admin users',
            'admin.impersonate' => 'Impersonate tenant users',
            'admin.tenants.view' => 'View tenants',
            'admin.tenants.create' => 'Create new tenants',
            'admin.tenants.update' => 'Edit existing tenants',
            'admin.tenants.delete' => 'Delete tenants',
            'admin.tenant-domains.view' => 'View tenant domains',
            'admin.tenant-domains.create' => 'Create new tenant domains',
            'admin.tenant-domains.update' => 'Edit existing tenant domains',
            'admin.tenant-domains.delete' => 'Delete tenant domains',
            'admin.groups.view' => 'View permission groups',
            'admin.groups.create' => 'Create new permission groups',
            'admin.groups.update' => 'Edit existing permission groups',
            'admin.groups.delete' => 'Delete permission groups',
            'admin.permissions.view' => 'View permissions reference',
            'admin.permissions.sync' => 'Sync permissions to database',
            'admin.permissions.delete' => 'Delete permissions',
            'admin.notifications.view' => 'View notifications',
            'admin.notifications.delete' => 'Delete notifications',
            'admin.settings.view' => 'View application settings',
            'admin.modules.view' => 'View installed modules',
            'admin.monitoring.view' => 'View monitoring dashboard',
            'admin.queue.view' => 'View queue status',
            'admin.git-update.view' => 'View and trigger git updates',
            'backups.view' => 'View backup configurations and history',
            'backups.create' => 'Create new backup configurations',
            'backups.update' => 'Edit existing backup configurations',
            'backups.delete' => 'Delete backup configurations and files',
            'backups.run' => 'Run a backup immediately',
            'backups.restore' => 'Restore from a backup (superadmin only)',
        ]);
    }
}
