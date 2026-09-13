<?php

declare(strict_types=1);

namespace Modules\Fax\Providers;

use Modules\Fax\Services\FaxService;
use Modules\Fax\Services\FaxServiceInterface;

/**
 * Service provider for the fax module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        FaxServiceInterface::class => FaxService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'fax';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Fax';
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
                'key' => 'admin.fax',
                'label' => 'admin.fax',
                'route' => 'panel.fax.index',
                'permission' => 'fax.view',
                'icon' => 'heroicon-o-printer',
                'parent' => 'pbx.features',
                'guard' => 'admin',
                'order' => 130,
            ],
        ];
    }

    /**
     * Register permissions for the fax module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'fax.view' => 'View fax inbox',
            'fax.send' => 'Send faxes',
            'fax.delete' => 'Delete faxes',
        ];
    }
}
