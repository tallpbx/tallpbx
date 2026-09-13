<?php

declare(strict_types=1);

namespace Modules\VoicemailMessages\Providers;

/**
 * Service provider for the voicemail-messages module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'voicemail-messages';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\VoicemailMessages';
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
                'key' => 'voicemail-messages',
                'label' => 'admin.voicemail_messages',
                'route' => 'panel.voicemail-messages.index',
                'permission' => 'voicemail-messages.view',
                'icon' => 'heroicon-o-envelope',
                'parent' => 'pbx.monitoring',
                'order' => 10,
            ],
        ];
    }

    /**
     * Register permissions for the voicemail-messages module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'voicemail-messages.view' => 'View voicemail messages',
            'voicemail-messages.delete' => 'Delete voicemail messages',
        ];
    }
}
