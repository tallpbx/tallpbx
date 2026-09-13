<?php

declare(strict_types=1);

namespace Modules\Emergency\Providers;

use Modules\Emergency\Services\EmergencyService;
use Modules\Emergency\Services\EmergencyServiceInterface;

/**
 * Service provider for the emergency module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        EmergencyServiceInterface::class => EmergencyService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags EmergencyService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(EmergencyServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'emergency';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Emergency';
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
                'key' => 'emergency',
                'label' => 'admin.emergency',
                'route' => 'panel.emergency.index',
                'permission' => 'emergency.view',
                'icon' => 'heroicon-o-exclamation-triangle',
                'parent' => 'pbx.routing',
                'order' => 110,
            ],
        ];
    }

    /**
     * Register permissions for the emergency module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'emergency.view' => 'View emergency config',
            'emergency.create' => 'Create emergency config',
            'emergency.edit' => 'Edit emergency config',
            'emergency.delete' => 'Delete emergency config',
        ];
    }
}
