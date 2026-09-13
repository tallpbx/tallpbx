<?php

declare(strict_types=1);

namespace Modules\SipAccounts\Providers;

use Modules\SipAccounts\Services\SipAccountService;
use Modules\SipAccounts\Services\SipAccountServiceInterface;

/**
 * Service provider for the sip-accounts module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        SipAccountServiceInterface::class => SipAccountService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'sip-accounts';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\SipAccounts';
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
        return [];
    }

    /**
     * Register permissions for the sip-accounts module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'sip-accounts.view' => 'View SIP accounts',
            'sip-accounts.create' => 'Create new SIP accounts',
            'sip-accounts.edit' => 'Edit existing SIP accounts',
            'sip-accounts.delete' => 'Delete SIP accounts',
        ];
    }
}
