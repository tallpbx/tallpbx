<?php

declare(strict_types=1);

namespace Modules\Bridges\Providers;

use Modules\Bridges\Services\BridgeService;

/**
 * Service provider for the bridges module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's services.
     *
     * Tags BridgeService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(BridgeService::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'bridges';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Bridges';
    }

    /**
     * Register sidebar navigation menu items.
     *
     * First item appears in the admin sidebar (guard: admin).
     * Second item appears in the client/tenant sidebar (guard: web)
     * so users can manage their own bridges.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            // Unified panel sidebar — visible to both admin and tenant users.
            // Permission gating via MenuService::cannotView() controls visibility.
            [
                'key' => 'bridges',
                'label' => 'admin.bridges',
                'route' => 'panel.bridges.index',
                'permission' => 'bridges.view',
                'icon' => 'heroicon-o-arrow-right-on-rectangle',
                'parent' => 'pbx.routing',
                'order' => 70,
            ],
        ];
    }

    /**
     * Register permissions for the bridges module.
     *
     * These permissions control access to viewing, creating, editing,
     * and deleting bridges in both the admin and client interfaces.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'bridges.view' => 'View call bridges',
            'bridges.create' => 'Create new call bridges',
            'bridges.edit' => 'Edit existing call bridges',
            'bridges.delete' => 'Delete call bridges',
        ];
    }
}
