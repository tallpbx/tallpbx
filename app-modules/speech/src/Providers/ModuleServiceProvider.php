<?php

declare(strict_types=1);

namespace Modules\Speech\Providers;

use Modules\Speech\Services\SpeechService;
use Modules\Speech\Services\SpeechServiceInterface;

/**
 * Service provider for the speech module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        SpeechServiceInterface::class => SpeechService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'speech';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Speech';
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
                'key' => 'speech',
                'label' => 'admin.speech',
                'route' => 'panel.speech.index',
                'permission' => 'speech.view',
                'icon' => 'heroicon-o-speaker-wave',
                'parent' => 'pbx.media',
                'order' => 20,
            ],
        ];
    }

    /**
     * Register permissions for the speech module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'speech.view' => 'View speech config',
            'speech.create' => 'Create speech config',
            'speech.edit' => 'Edit speech config',
            'speech.delete' => 'Delete speech config',
        ];
    }
}
