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
        return [];
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
