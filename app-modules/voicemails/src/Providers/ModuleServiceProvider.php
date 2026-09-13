<?php

declare(strict_types=1);

namespace Modules\Voicemails\Providers;

use Modules\Voicemails\Services\VoicemailService;
use Modules\Voicemails\Services\VoicemailServiceInterface;

/**
 * Service provider for the voicemails module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        VoicemailServiceInterface::class => VoicemailService::class,
    ];

    /**
     * Register the module's services.
     *
     * Tags VoicemailService as a dialplan XML contributor.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag(VoicemailServiceInterface::class, 'dialplan.xml');
    }

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'voicemails';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Voicemails';
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
                'key' => 'voicemails',
                'label' => 'admin.voicemails',
                'route' => 'panel.voicemails.index',
                'permission' => 'voicemails.view',
                'icon' => 'heroicon-o-inbox',
                'parent' => 'pbx.features',
                'order' => 10,
            ],
        ];
    }

    /**
     * Register permissions for the voicemails module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'voicemails.view' => 'View voicemail mailboxes',
            'voicemails.create' => 'Create new voicemail mailboxes',
            'voicemails.edit' => 'Edit existing voicemail mailboxes',
            'voicemails.delete' => 'Delete voicemail mailboxes',
        ];
    }
}
