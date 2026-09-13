<?php

declare(strict_types=1);

namespace Modules\SmtpConnector\Providers;

use Modules\SmtpConnector\Services\SmtpConnectorService;
use Modules\SmtpConnector\Services\SmtpConnectorServiceInterface;

/**
 * Service provider for the SMTP Connector module.
 *
 * Registers the SMTP configuration Livewire component in the admin
 * panel sidebar so superadmins can configure SMTP delivery without
 * editing .env files.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        SmtpConnectorServiceInterface::class => SmtpConnectorService::class,
    ];

    /**
     * Kebab-case module identifier used for view namespace,
     * Livewire path, and route prefix.
     */
    protected function moduleName(): string
    {
        return 'smtp-connector';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\SmtpConnector';
    }

    /**
     * Menu items for the unified panel sidebar.
     *
     * Only superadmins can view SMTP configuration — permission
     * gating is handled by MenuService::cannotView().
     */
    protected function menuItems(): array
    {
        return [
            [
                'key' => 'smtp-connector',
                'label' => 'admin.smtp_connector',
                'route' => 'panel.smtp-connector.edit',
                'permission' => 'smtp-connector.view',
                'icon' => 'heroicon-o-envelope',
                'order' => 51,
            ],
        ];
    }

    /**
     * Permissions for the SMTP connector module.
     */
    protected function permissions(): array
    {
        return [
            'smtp-connector.view' => 'View SMTP configuration',
            'smtp-connector.update' => 'Update SMTP configuration',
        ];
    }
}
