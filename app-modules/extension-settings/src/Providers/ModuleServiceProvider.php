<?php

declare(strict_types=1);

namespace Modules\ExtensionSettings\Providers;

use Modules\ExtensionSettings\Services\ExtensionSettingService;
use Modules\ExtensionSettings\Services\ExtensionSettingServiceInterface;

/**
 * Service provider for the extension-settings module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        ExtensionSettingServiceInterface::class => ExtensionSettingService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'extension-settings';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\ExtensionSettings';
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
            // Unified panel sidebar — extension-level settings are reachable from
            // nav now (previously workbench-only); parent mirrors category "accounts".
            [
                'key' => 'extension-settings',
                'label' => 'admin.extension_settings',
                'route' => 'panel.extension-settings.index',
                'permission' => 'extension-settings.view',
                'icon' => 'heroicon-o-adjustments-horizontal',
                'parent' => 'pbx.accounts',
                'order' => 35,
            ],
        ];
    }

    /**
     * Register permissions for the extension-settings module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'extension-settings.view' => 'View extension settings',
            'extension-settings.create' => 'Create extension settings',
            'extension-settings.edit' => 'Edit extension settings',
            'extension-settings.delete' => 'Delete extension settings',
        ];
    }
}
