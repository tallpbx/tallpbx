<?php

declare(strict_types=1);

namespace Modules\Transcribe\Providers;

use Modules\Transcribe\Services\TranscribeService;
use Modules\Transcribe\Services\TranscribeServiceInterface;

/**
 * Service provider for the transcribe module.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        TranscribeServiceInterface::class => TranscribeService::class,
    ];

    /**
     * Kebab-case module identifier used as the view namespace,
     * route prefix, and permission group key.
     */
    protected function moduleName(): string
    {
        return 'transcribe';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\Transcribe';
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
                'key' => 'admin.transcribe',
                'label' => 'admin.transcribe',
                'route' => 'panel.transcribe.index',
                'permission' => 'transcribe.view',
                'icon' => 'heroicon-o-microphone',
                'parent' => 'pbx.advanced',
                'guard' => 'admin',
                'order' => 110,
            ],
        ];
    }

    /**
     * Register permissions for the transcribe module.
     *
     * These permissions control access to viewing, creating,
     * editing, and deleting records.
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'transcribe.view' => 'View transcriptions',
            'transcribe.delete' => 'Delete transcriptions',
        ];
    }
}
