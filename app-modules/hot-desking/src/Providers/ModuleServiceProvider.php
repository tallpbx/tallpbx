<?php

declare(strict_types=1);

namespace Modules\HotDesking\Providers;

use Modules\HotDesking\Services\HotDeskingService;
use Modules\HotDesking\Services\HotDeskingServiceInterface;

/**
 * Service provider for the hot-desking module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        HotDeskingServiceInterface::class => HotDeskingService::class,
    ];

    /**
     * Register module services and tag as dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(HotDeskingServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Return the module's kebab-case registry name.
     */
    protected function moduleName(): string
    {
        return 'hot-desking';
    }

    /**
     * Return the module's root PHP namespace.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\HotDesking';
    }

    /**
     * Register sidebar navigation menu items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            [
                'key' => 'hot-desking',
                'label' => 'admin.hot_desking',
                'route' => 'panel.hot-desking.index',
                'permission' => 'hot-desking.view',
                'icon' => 'heroicon-o-computer-desktop',
                'parent' => 'pbx.features.routing',
                'order' => 85,
            ],
        ];
    }

    /**
     * Register module permissions.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'hot-desking.view' => 'View hot desking sessions',
            'hot-desking.create' => 'Create hot desking sessions',
            'hot-desking.edit' => 'Edit hot desking sessions',
            'hot-desking.delete' => 'Delete hot desking sessions',
        ];
    }
}
