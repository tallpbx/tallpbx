<?php

declare(strict_types=1);

namespace Modules\SipTrunks\Providers;

use Modules\SipTrunks\Services\SipTrunkService;
use Modules\SipTrunks\Services\SipTrunkServiceInterface;

/**
 * Service provider for the sip-trunks module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        SipTrunkServiceInterface::class => SipTrunkService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'sip-trunks';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\SipTrunks';
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
                'key' => 'sip-trunks',
                'label' => 'admin.sip_trunks',
                'route' => 'panel.sip-trunks.index',
                'permission' => 'sip-trunks.view',
                'icon' => 'heroicon-o-arrows-right-left',
                'parent' => 'pbx.connectivity',
                'order' => 30,
            ],
        ];
    }

    /**
     * Register permissions for the sip-trunks module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'sip-trunks.view' => 'View SIP trunks',
            'sip-trunks.create' => 'Create SIP trunks',
            'sip-trunks.edit' => 'Edit SIP trunks',
            'sip-trunks.delete' => 'Delete SIP trunks',
        ];
    }
}
