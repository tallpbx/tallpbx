<?php

declare(strict_types=1);

namespace Modules\Provision\Providers;

use Modules\Provision\Services\ProvisionService;
use Modules\Provision\Services\ProvisionServiceInterface;

/**
 * Service provider for the provision module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $singletons = [
        ProvisionServiceInterface::class => ProvisionService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'provision';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Provision';
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
                'key' => 'provision',
                'label' => 'admin.provision',
                'route' => 'panel.provision.templates.index',
                'permission' => 'provision.view',
                'icon' => 'heroicon-o-cpu-chip',
                'parent' => 'pbx.features',
                'order' => 10,
            ],
        ];
    }

    /**
     * Register permissions for the provision module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'provision.view' => 'View provisioning templates',
            'provision.create' => 'Create provisioning templates',
            'provision.edit' => 'Edit provisioning templates',
            'provision.delete' => 'Delete provisioning templates',
        ];
    }
}
