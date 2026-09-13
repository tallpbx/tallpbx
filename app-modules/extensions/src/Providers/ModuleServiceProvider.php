<?php

declare(strict_types=1);

namespace Modules\Extensions\Providers;

use Modules\Extensions\Services\ExtensionService;
use Modules\Extensions\Services\ExtensionServiceInterface;

/**
 * Service provider for the extensions module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        ExtensionServiceInterface::class => ExtensionService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'extensions';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Extensions';
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
                'key' => 'extensions',
                'label' => 'admin.extensions',
                'route' => 'panel.extensions.index',
                'permission' => 'extensions.view',
                'icon' => 'heroicon-o-phone',
                'parent' => 'pbx.accounts',
                'order' => 20,
            ],
        ];
    }

    /**
     * Register permissions for the extensions module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'extensions.view' => 'View extensions',
            'extensions.create' => 'Create new extensions',
            'extensions.edit' => 'Edit existing extensions',
            'extensions.delete' => 'Delete extensions',
            'extensions.import' => 'Import extensions from file',
        ];
    }
}
