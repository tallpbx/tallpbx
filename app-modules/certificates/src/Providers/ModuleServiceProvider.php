<?php

declare(strict_types=1);

namespace Modules\Certificates\Providers;

use Modules\Certificates\Console\Commands\CertificateDeployCommand;
use Modules\Certificates\Console\Commands\CertificateRenewCommand;
use Modules\Certificates\Console\Commands\CertificateStatusCommand;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Contracts\CertificateParserServiceInterface;
use Modules\Certificates\Contracts\CertificateValidatorServiceInterface;
use Modules\Certificates\Contracts\LetsEncryptAcmeServiceInterface;
use Modules\Certificates\Contracts\SelfSignedGeneratorServiceInterface;
use Modules\Certificates\Services\CertificateDeploymentService;
use Modules\Certificates\Services\CertificateExecutor;
use Modules\Certificates\Services\CertificateParserService;
use Modules\Certificates\Services\CertificateValidatorService;
use Modules\Certificates\Services\LetsEncryptAcmeService;
use Modules\Certificates\Services\SelfSignedGeneratorService;

/**
 * Registers the certificates module with the TallPBX module system.
 *
 * Provides cryptographic certificate management, automated ACME renewals,
 * and unified Nginx Web and FreeSWITCH Telephony deployments.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Return the module's kebab-case registry name.
     */
    protected function moduleName(): string
    {
        return 'certificates';
    }

    /**
     * Return the module's root PHP namespace.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\\Certificates';
    }

    /**
     * Register sidebar navigation menu items.
     *
     * Places Certificate Manager beside Security at order 39.5.
     * Visibility is governed by the 'certificates.view' permission.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            [
                'key' => 'certificates',
                'label' => 'admin.certificates',
                'route' => 'panel.certificates.index',
                'permission' => 'certificates.view',
                'icon' => 'heroicon-o-key',
                'guard' => 'admin',
                'order' => 39.5,
            ],
        ];
    }

    /**
     * Register granular permissions for the certificates module.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'certificates.view' => 'View certificates dashboard, expiration status, and audit logs',
            'certificates.create' => 'Issue Let\'s Encrypt certificates, import custom PEM certificates, and create self-signed certificates',
            'certificates.deploy' => 'Deploy certificates to Nginx Web and FreeSWITCH Telephony services',
            'certificates.renew' => 'Trigger manual Let\'s Encrypt certificate renewals',
            'certificates.delete' => 'Delete inactive certificates and DNS credentials',
        ];
    }

    /**
     * Return the Artisan console commands to register for this module.
     *
     * @return array<int, class-string>
     */
    protected function consoleCommands(): array
    {
        return [
            CertificateRenewCommand::class,
            CertificateDeployCommand::class,
            CertificateStatusCommand::class,
        ];
    }

    /**
     * All container singletons and bindings registered by this module.
     *
     * @var array<class-string, class-string>
     */
    public $bindings = [
        CertificateExecutorInterface::class => CertificateExecutor::class,
        CertificateParserServiceInterface::class => CertificateParserService::class,
        CertificateValidatorServiceInterface::class => CertificateValidatorService::class,
        CertificateDeploymentServiceInterface::class => CertificateDeploymentService::class,
        SelfSignedGeneratorServiceInterface::class => SelfSignedGeneratorService::class,
        LetsEncryptAcmeServiceInterface::class => LetsEncryptAcmeService::class,
    ];
}
