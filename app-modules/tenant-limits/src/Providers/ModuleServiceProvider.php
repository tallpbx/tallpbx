<?php

declare(strict_types=1);

namespace Modules\TenantLimits\Providers;

use Modules\TenantLimits\Support\TenantLimitsUninstaller;

/**
 * Service provider for the tenant-limits module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's destructive uninstall handler.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(TenantLimitsUninstaller::class, 'module.uninstallers');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'tenant-limits';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\TenantLimits';
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
                'key' => 'tenant-limits',
                'label' => 'admin.tenant_limits',
                'route' => 'panel.tenant-limits.index',
                'permission' => 'tenant-limits.view',
                'icon' => 'heroicon-o-chart-bar',
                'parent' => 'pbx.advanced',
                'order' => 20,
            ],
        ];
    }

    /**
     * Register permissions for the tenant-limits module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'tenant-limits.view' => 'View tenant limits',
            'tenant-limits.create' => 'Create tenant limits',
            'tenant-limits.edit' => 'Edit tenant limits',
            'tenant-limits.delete' => 'Delete tenant limits',
        ];
    }
}
