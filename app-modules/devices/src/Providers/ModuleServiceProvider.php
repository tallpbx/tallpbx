<?php

declare(strict_types=1);

namespace Modules\Devices\Providers;

use Modules\Devices\Services\DeviceService;
use Modules\Devices\Services\DeviceServiceInterface;

/**
 * Service provider for the devices module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        DeviceServiceInterface::class => DeviceService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'devices';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Devices';
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
                'key' => 'devices',
                'label' => 'admin.devices',
                'route' => 'panel.devices.index',
                'permission' => 'devices.view',
                'icon' => 'heroicon-o-computer-desktop',
                'parent' => 'pbx.accounts',
                'order' => 10,
            ],
        ];
    }

    /**
     * Register permissions for the devices module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'devices.view' => 'View SIP devices',
            'devices.create' => 'Create new SIP devices',
            'devices.edit' => 'Edit existing SIP devices',
            'devices.delete' => 'Delete SIP devices',
        ];
    }
}
