<?php

declare(strict_types=1);

namespace Modules\OutboundRoutes\Providers;

use Modules\OutboundRoutes\Services\OutboundRouteService;
use Modules\OutboundRoutes\Services\OutboundRouteServiceInterface;

/**
 * Service provider for the outbound-routes module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        OutboundRouteServiceInterface::class => OutboundRouteService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags OutboundRouteService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(OutboundRouteServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'outbound-routes';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\OutboundRoutes';
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
                'key' => 'outbound-routes',
                'label' => 'admin.outbound_routes',
                'route' => 'panel.outbound-routes.index',
                'permission' => 'outbound-routes.view',
                'icon' => 'heroicon-o-arrows-right-left',
                'parent' => 'pbx.routing',
                'order' => 6,
            ],
        ];
    }

    /**
     * Register permissions for the outbound-routes module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'outbound-routes.view' => 'View outbound routes',
            'outbound-routes.create' => 'Create new outbound routes',
            'outbound-routes.edit' => 'Edit existing outbound routes',
            'outbound-routes.delete' => 'Delete outbound routes',
        ];
    }
}
