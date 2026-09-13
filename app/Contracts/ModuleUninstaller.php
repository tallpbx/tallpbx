<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Module;

/**
 * Defines destructive uninstall behavior for one module.
 */
interface ModuleUninstaller
{
    /**
     * Return the registry name of the module this uninstaller owns.
     */
    public function moduleName(): string;

    /**
     * Determine whether the module can be uninstalled right now.
     */
    public function canUninstall(Module $module): bool;

    /**
     * Describe what data will be removed before confirmation.
     *
     * @return array<int, string>
     */
    public function previewUninstall(Module $module): array;

    /**
     * Permanently remove module-owned data such as tables or files.
     */
    public function uninstall(Module $module): void;
}
