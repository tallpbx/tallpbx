<?php

declare(strict_types=1);

namespace Modules\NumberTranslations\Providers;

use Modules\NumberTranslations\Services\NumberTranslationService;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;

/**
 * Service provider for the number-translations module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        NumberTranslationServiceInterface::class => NumberTranslationService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'number-translations';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\NumberTranslations';
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
                'key' => 'number-translations',
                'label' => 'admin.number_translations',
                'route' => 'panel.number-translations.index',
                'permission' => 'number-translations.view',
                'icon' => 'heroicon-o-arrows-right-left',
                'parent' => 'pbx.routing',
                'order' => 100,
            ],
        ];
    }

    /**
     * Register permissions for the number-translations module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'number-translations.view' => 'View number translations',
            'number-translations.create' => 'Create number translations',
            'number-translations.edit' => 'Edit number translations',
            'number-translations.delete' => 'Delete number translations',
        ];
    }
}
