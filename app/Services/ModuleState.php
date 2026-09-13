<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Module;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Resolves enabled/disabled module state from the TallPBX module registry.
 */
class ModuleState
{
    /**
     * Cached result for whether the module registry can be queried.
     */
    private ?bool $registryAvailable = null;

    /**
     * Cached result for whether lifecycle status is available.
     */
    private ?bool $statusAvailable = null;

    /**
     * Whether module states have been loaded for the current request.
     */
    private bool $modulesLoaded = false;

    /**
     * Cached enabled state by module name for this service instance.
     *
     * The service is registered as a singleton, so this avoids repeated
     * registry queries during one HTTP request. PHP-FPM creates a fresh
     * Laravel app for the next request, so enable/disable changes are still
     * picked up on the next XML handler call.
     *
     * @var array<string, bool>
     */
    private array $enabledModules = [];

    /**
     * Determine whether a module should participate in runtime registration.
     */
    public function isEnabled(string $module): bool
    {
        if (! $this->registryAvailable()) {
            return true;
        }

        if (! $this->modulesLoaded) {
            $this->loadModuleStates();
        }

        return $this->enabledModules[$module] ?? true;
    }

    /**
     * Determine whether the module owning a class is enabled.
     */
    public function isEnabledForClass(object|string $class): bool
    {
        $module = $this->moduleNameForClass($class);

        if ($module === null) {
            return true;
        }

        return $this->isEnabled($module);
    }

    /**
     * Resolve a TallPBX module name from a Modules\PascalName\... class.
     */
    private function moduleNameForClass(object|string $class): ?string
    {
        $className = is_object($class) ? $class::class : $class;

        if (! str_starts_with($className, 'Modules\\')) {
            return null;
        }

        $parts = explode('\\', $className);
        $moduleSegment = $parts[1] ?? null;

        if ($moduleSegment === null || $moduleSegment === '') {
            return null;
        }

        return Str::kebab($moduleSegment);
    }

    /**
     * Load all registered module enabled states for the current request.
     */
    private function loadModuleStates(): void
    {
        $columns = $this->statusAvailable()
            ? ['name', 'enabled', 'status']
            : ['name', 'enabled'];

        // Load every registry row once so dialplan contributor checks do not
        // run one modules query per module on every XML handler request.
        Module::query()
            ->get($columns)
            ->each(function (Module $module): void {
                if ($module->status === Module::StatusUninstalled || $module->status === Module::StatusDisabled) {
                    $this->enabledModules[$module->name] = false;

                    return;
                }

                $this->enabledModules[$module->name] = (bool) $module->enabled;
            });

        $this->modulesLoaded = true;
    }

    /**
     * Check whether the modules table is queryable during early boot.
     */
    private function registryAvailable(): bool
    {
        if ($this->registryAvailable !== null) {
            return $this->registryAvailable;
        }

        try {
            return $this->registryAvailable = Schema::hasTable('modules');
        } catch (\Throwable) {
            return $this->registryAvailable = false;
        }
    }

    /**
     * Check whether the lifecycle status column exists during upgrades.
     */
    private function statusAvailable(): bool
    {
        if ($this->statusAvailable !== null) {
            return $this->statusAvailable;
        }

        try {
            return $this->statusAvailable = Schema::hasColumn('modules', 'status');
        } catch (\Throwable) {
            return $this->statusAvailable = false;
        }
    }
}
