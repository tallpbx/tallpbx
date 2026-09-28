<?php

declare(strict_types=1);

namespace Modules\Backups\Providers;

use Modules\Backups\Services\RestoreCommandRunnerInterface;
use Modules\Backups\Services\SymfonyRestoreCommandRunner;

/**
 * Service provider for the Backups module.
 *
 * Registers backup management Livewire components in the admin panel
 * sidebar so superadmins can configure, run, and restore backups.
 */
class ModuleServiceProvider extends \App\Support\ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public $bindings = [
        RestoreCommandRunnerInterface::class => SymfonyRestoreCommandRunner::class,
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
     * The Admin module owns backup panel routes so they are registered once.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function routeGroups(): array
    {
        return [];
    }
}
