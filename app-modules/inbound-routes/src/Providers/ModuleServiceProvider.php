<?php

declare(strict_types=1);

namespace Modules\InboundRoutes\Providers;

use Modules\InboundRoutes\Services\InboundRouteService;
use Modules\InboundRoutes\Services\InboundRouteServiceInterface;

/**
 * Service provider for the inbound-routes module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        InboundRouteServiceInterface::class => InboundRouteService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags InboundRouteService as a dialplan XML contributor so the
     * XmlHandlerController includes inbound route rules in the
     * dialplan served to FreeSWITCH.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(InboundRouteServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'inbound-routes';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\InboundRoutes';
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
                'key' => 'inbound-routes',
                'label' => 'admin.inbound_routes',
                'route' => 'panel.inbound-routes.index',
                'permission' => 'inbound-routes.view',
                'icon' => 'heroicon-o-globe-alt',
                'parent' => 'pbx.routing',
                'order' => 5,
            ],
        ];
    }

    /**
     * Register permissions for the inbound-routes module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'inbound-routes.view' => 'View inbound routes',
            'inbound-routes.create' => 'Create new inbound routes',
            'inbound-routes.edit' => 'Edit existing inbound routes',
            'inbound-routes.delete' => 'Delete inbound routes',
        ];
    }
}
