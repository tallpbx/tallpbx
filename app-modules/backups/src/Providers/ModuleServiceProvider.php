<?php

declare(strict_types=1);

namespace Modules\Backups\Providers;

use Modules\Backups\Services\BackupService;
use Modules\Backups\Services\BackupServiceInterface;
use Modules\Backups\Services\RestoreCommandRunnerInterface;
use Modules\Backups\Services\SymfonyRestoreCommandRunner;

/**
 * Service provider for the Backups module.
 *
 * Registers backup management Livewire components in the admin panel
 * sidebar so superadmins can configure, run, and restore backups. The
 * module owns its routes, menu entry, and permissions so disaster
 * recovery remains a self-contained Core Administration capability.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        RestoreCommandRunnerInterface::class => SymfonyRestoreCommandRunner::class,
        BackupServiceInterface::class => BackupService::class,
    ];

    /**
     * Kebab-case module identifier for view namespace, route prefix,
     * and Livewire namespace.
     */
    protected function moduleName(): string
    {
        return 'backups';
    }

    /**
     * PHP root namespace for this module's classes.
     */
    protected function moduleNamespace(): string
    {
        return 'Modules\\Backups';
    }

    /**
     * The sidebar menu entry for the module.
     *
     * A flat top-level item (parent: null): Backups is a Core Administration
     * utility, not a child of the PBX feature groups (plan §1.5, Task 5).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function menuItems(): array
    {
        return [
            [
                'key' => 'backups',
                'label' => 'admin.backups',
                'route' => 'panel.backups.index',
                'permission' => 'backups.view',
                'icon' => 'heroicon-o-archive-box-arrow-down',
                'parent' => null,
                'order' => 85,
            ],
        ];
    }

    /**
     * The permissions this module registers.
     *
     * Format: [permission_key => human_readable_description].
     *
     * @return array<string, string>
     */
    protected function permissions(): array
    {
        return [
            'backups.view' => 'View backup configurations and history',
            'backups.create' => 'Create new backup configurations',
            'backups.update' => 'Edit existing backup configurations',
            'backups.delete' => 'Delete backup configurations and files',
            'backups.run' => 'Run a backup immediately',
            'backups.restore' => 'Restore from a backup (superadmin only)',
        ];
    }
}
