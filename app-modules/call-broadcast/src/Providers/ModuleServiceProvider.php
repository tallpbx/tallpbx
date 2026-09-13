<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Providers;

use Modules\CallBroadcast\Support\CallBroadcastUninstaller;

/**
 * Service provider for the call-broadcast module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's destructive uninstall handler.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(CallBroadcastUninstaller::class, 'module.uninstallers');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'call-broadcast';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\CallBroadcast';
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
                'key' => 'admin.call-broadcast',
                'label' => 'admin.call_broadcasts',
                'route' => 'panel.call-broadcast.index',
                'permission' => 'call-broadcast.view',
                'icon' => 'heroicon-o-megaphone',
                'parent' => 'pbx.features',
                'guard' => 'admin',
                'order' => 140,
            ],
        ];
    }

    /**
     * Register permissions for the call-broadcast module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'call-broadcast.view' => 'View call broadcasts',
            'call-broadcast.create' => 'Create new call broadcasts',
            'call-broadcast.delete' => 'Delete call broadcasts',
        ];
    }
}
