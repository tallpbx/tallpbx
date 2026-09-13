<?php

declare(strict_types=1);

namespace Modules\Logs\Providers;

/**
 * Service provider for the logs module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'logs';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Logs';
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
            // Admin sidebar — visible to users with the 'admin' guard
            [
                'key' => 'admin.logs',
                'label' => 'admin.logs',
                'route' => 'panel.logs.index',
                'permission' => 'logs.view',
                'icon' => 'heroicon-o-document-text',
                'parent' => 'admin.system',
                'guard' => 'admin',
                'order' => 39,
            ],
        ];
    }

    /**
     * Register permissions for the logs module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'logs.view' => 'View application logs',
        ];
    }
}
