<?php

declare(strict_types=1);

namespace Modules\EmailQueue\Providers;

use Modules\EmailQueue\Support\EmailQueueUninstaller;

/**
 * Service provider for the email-queue module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's destructive uninstall handler.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(EmailQueueUninstaller::class, 'module.uninstallers');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'email-queue';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\EmailQueue';
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
                'key' => 'email-queue',
                'label' => 'admin.email_queue',
                'route' => 'panel.email-queue.index',
                'permission' => 'email-queue.view',
                'icon' => 'heroicon-o-envelope',
                'parent' => 'pbx.monitoring',
                'order' => 50,
            ],
        ];
    }

    /**
     * Register permissions for the email-queue module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'email-queue.view' => 'View email queue',
            'email-queue.delete' => 'Delete queued emails',
        ];
    }
}
