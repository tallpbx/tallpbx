<?php

declare(strict_types=1);

namespace Modules\EmailConnector\Providers;

use Modules\EmailConnector\Services\EmailConnectorService;
use Modules\EmailConnector\Services\EmailConnectorServiceInterface;

/**
 * Service provider for the Email Connector module.
 *
 * Registers the email configuration Livewire component in the admin
 * panel sidebar so superadmins can configure SMTP/OAuth email delivery
 * without editing .env files.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        EmailConnectorServiceInterface::class => EmailConnectorService::class,
    ];

    /**
     * Kebab-case module identifier used for view namespace,
     * Livewire path, and route prefix.
     */
    protected function moduleName(): string
    {
        return 'email-connector';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\EmailConnector';
    }

    /**
     * Menu items for the unified panel sidebar.
     *
     * Only superadmins can view email connector configuration — permission
     * gating is handled by MenuService::cannotView().
     */
    protected function menuItems(): array
    {
        return [
            [
                'key' => 'email-connector',
                'label' => 'admin.email_connector',
                'route' => 'panel.email-connector.edit',
                'permission' => 'email-connector.view',
                'icon' => 'heroicon-o-envelope',
                'order' => 51,
            ],
        ];
    }

    /**
     * Permissions for the Email Connector module.
     */
    protected function permissions(): array
    {
        return [
            'email-connector.view' => 'View Email Connector configuration',
            'email-connector.update' => 'Update Email Connector configuration',
        ];
    }
}
