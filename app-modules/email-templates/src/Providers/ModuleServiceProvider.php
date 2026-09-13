<?php

declare(strict_types=1);

namespace Modules\EmailTemplates\Providers;

use Modules\EmailTemplates\Support\EmailTemplatesUninstaller;

/**
 * Service provider for the email-templates module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Register the module's destructive uninstall handler.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(EmailTemplatesUninstaller::class, 'module.uninstallers');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'email-templates';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\EmailTemplates';
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
                'key' => 'email-templates',
                'label' => 'admin.email_templates',
                'route' => 'panel.email-templates.index',
                'permission' => 'email-templates.view',
                'icon' => 'heroicon-o-document-text',
                'parent' => 'pbx.advanced',
                'order' => 40,
            ],
        ];
    }

    /**
     * Register permissions for the email-templates module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'email-templates.view' => 'View email templates',
            'email-templates.create' => 'Create email templates',
            'email-templates.edit' => 'Edit email templates',
            'email-templates.delete' => 'Delete email templates',
        ];
    }
}
