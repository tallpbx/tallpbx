<?php

declare(strict_types=1);

namespace Modules\CallFlows\Providers;

use Modules\CallFlows\Services\CallFlowService;

/**
 * Service provider for the call-flows module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's services.
     *
     * Tags CallFlowService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(CallFlowService::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'call-flows';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\CallFlows';
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
                'key' => 'call-flows',
                'label' => 'admin.call_flows',
                'route' => 'panel.call-flows.index',
                'permission' => 'call-flows.view',
                'icon' => 'heroicon-o-arrow-path',
                'parent' => 'pbx.routing',
                'order' => 50,
            ],
        ];
    }

    /**
     * Register permissions for the call-flows module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'call-flows.view' => 'View call flows',
            'call-flows.create' => 'Create new call flows',
            'call-flows.edit' => 'Edit existing call flows',
            'call-flows.delete' => 'Delete call flows',
        ];
    }
}
