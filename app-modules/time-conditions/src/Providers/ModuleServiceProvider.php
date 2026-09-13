<?php

declare(strict_types=1);

namespace Modules\TimeConditions\Providers;

use Modules\TimeConditions\Services\TimeConditionService;

/**
 * Service provider for the time-conditions module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's services.
     *
     * Tags TimeConditionService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(TimeConditionService::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'time-conditions';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\TimeConditions';
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
                'key' => 'time-conditions',
                'label' => 'admin.time_conditions',
                'route' => 'panel.time-conditions.index',
                'permission' => 'time-conditions.view',
                'icon' => 'heroicon-o-clock',
                'parent' => 'pbx.features',
                'order' => 30,
            ],
        ];
    }

    /**
     * Register permissions for the time-conditions module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'time-conditions.view' => 'View time conditions',
            'time-conditions.create' => 'Create new time conditions',
            'time-conditions.edit' => 'Edit existing time conditions',
            'time-conditions.delete' => 'Delete time conditions',
        ];
    }
}
