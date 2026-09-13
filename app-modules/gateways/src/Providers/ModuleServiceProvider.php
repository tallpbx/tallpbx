<?php

declare(strict_types=1);

namespace Modules\Gateways\Providers;

use Modules\Gateways\Services\GatewayService;
use Modules\Gateways\Services\GatewayServiceInterface;

/**
 * Service provider for the gateways module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        GatewayServiceInterface::class => GatewayService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'gateways';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Gateways';
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
                'key' => 'gateways',
                'label' => 'admin.gateways',
                'route' => 'panel.gateways.index',
                'permission' => 'gateways.view',
                'icon' => 'heroicon-o-globe-alt',
                'parent' => 'pbx.accounts',
                'order' => 30,
            ],
        ];
    }

    /**
     * Register permissions for the gateways module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'gateways.view' => 'View gateways',
            'gateways.create' => 'Create new gateways',
            'gateways.edit' => 'Edit existing gateways',
            'gateways.delete' => 'Delete gateways',
        ];
    }
}
