<?php

declare(strict_types=1);

namespace Modules\Destinations\Providers;

use Modules\Destinations\Services\DestinationService;
use Modules\Destinations\Services\DestinationServiceInterface;

/**
 * Service provider for the destinations module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        DestinationServiceInterface::class => DestinationService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'destinations';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Destinations';
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
                'key' => 'destinations',
                'label' => 'admin.destinations',
                'route' => 'panel.destinations.index',
                'permission' => 'destinations.view',
                'icon' => 'heroicon-o-map-pin',
                'parent' => 'pbx.routing',
                'order' => 110,
            ],
        ];
    }

    /**
     * Register permissions for the destinations module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'destinations.view' => 'View call destinations',
            'destinations.create' => 'Create new call destinations',
            'destinations.edit' => 'Edit existing call destinations',
            'destinations.delete' => 'Delete call destinations',
        ];
    }
}
