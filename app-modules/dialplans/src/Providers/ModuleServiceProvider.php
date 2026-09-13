<?php

declare(strict_types=1);

namespace Modules\Dialplans\Providers;

use Modules\Dialplans\Services\DialplanService;
use Modules\Dialplans\Services\DialplanServiceInterface;

/**
 * Service provider for the dialplans module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        DialplanServiceInterface::class => DialplanService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'dialplans';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Dialplans';
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
                'key' => 'dialplans',
                'label' => 'admin.dialplans',
                'route' => 'panel.dialplans.index',
                'permission' => 'dialplans.view',
                'icon' => 'heroicon-o-map',
                'parent' => 'pbx.advanced',
                'order' => 30,
            ],
        ];
    }

    /**
     * Register permissions for the dialplans module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'dialplans.view' => 'View dialplans',
            'dialplans.create' => 'Create new dialplans',
            'dialplans.edit' => 'Edit existing dialplans',
            'dialplans.delete' => 'Delete dialplans',
        ];
    }
}
