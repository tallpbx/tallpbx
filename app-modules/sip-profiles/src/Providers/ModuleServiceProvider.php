<?php

declare(strict_types=1);

namespace Modules\SipProfiles\Providers;

use Modules\SipProfiles\Services\SipProfileService;
use Modules\SipProfiles\Services\SipProfileServiceInterface;

/**
 * Service provider for the sip-profiles module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        SipProfileServiceInterface::class => SipProfileService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'sip-profiles';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\SipProfiles';
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
        return [
            // Unified panel sidebar — visible to both admin and tenant users.
            // Permission gating via MenuService::cannotView() controls visibility.
            [
                'key' => 'sip-profiles',
                'label' => 'admin.sip_profiles',
                'route' => 'panel.sip-profiles.index',
                'permission' => 'sip-profiles.view',
                'icon' => 'heroicon-o-wrench',
                'parent' => 'pbx.connectivity',
                'order' => 20,
            ],
        ];
    }

    /**
     * Register permissions for the sip-profiles module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'sip-profiles.view' => 'View SIP profiles',
            'sip-profiles.create' => 'Create new SIP profiles',
            'sip-profiles.edit' => 'Edit existing SIP profiles',
            'sip-profiles.delete' => 'Delete SIP profiles',
        ];
    }
}
