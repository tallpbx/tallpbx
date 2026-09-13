<?php

declare(strict_types=1);

namespace Modules\PinNumbers\Providers;

use Modules\PinNumbers\Services\PinNumberService;
use Modules\PinNumbers\Support\PinNumbersUninstaller;

/**
 * Service provider for the pin-numbers module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's destructive uninstall handler.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(PinNumbersUninstaller::class, 'module.uninstallers');

        // The PIN flow contributes dialplan XML for the interactive auth.
        $this->app->tag(PinNumberService::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'pin-numbers';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\PinNumbers';
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
                'key' => 'pin-numbers',
                'label' => 'admin.pin_numbers',
                'route' => 'panel.pin-numbers.index',
                'permission' => 'pin-numbers.view',
                'icon' => 'heroicon-o-hashtag',
                'parent' => 'pbx.features',
                'order' => 20,
            ],
        ];
    }

    /**
     * Register permissions for the pin-numbers module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'pin-numbers.view' => 'View PIN numbers',
            'pin-numbers.create' => 'Create new PIN numbers',
            'pin-numbers.edit' => 'Edit existing PIN numbers',
            'pin-numbers.delete' => 'Delete PIN numbers',
        ];
    }
}
