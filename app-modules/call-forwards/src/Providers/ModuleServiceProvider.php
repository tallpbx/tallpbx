<?php

declare(strict_types=1);

namespace Modules\CallForwards\Providers;

use Modules\CallForwards\Services\CallForwardService;
use Modules\CallForwards\Services\CallForwardServiceInterface;

/**
 * Service provider for the call-forwards module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        CallForwardServiceInterface::class => CallForwardService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags CallForwardService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(CallForwardServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'call-forwards';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\CallForwards';
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
                'key' => 'call-forwards',
                'label' => 'admin.call_forwards',
                'route' => 'panel.call-forwards.index',
                'permission' => 'call-forwards.view',
                'icon' => 'heroicon-o-phone-arrow-up-right',
                'parent' => 'pbx.routing',
                'order' => 60,
            ],
        ];
    }

    /**
     * Register permissions for the call-forwards module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'call-forwards.view' => 'View call forward rules',
            'call-forwards.create' => 'Create new call forward rules',
            'call-forwards.edit' => 'Edit existing call forward rules',
            'call-forwards.delete' => 'Delete call forward rules',
        ];
    }
}
