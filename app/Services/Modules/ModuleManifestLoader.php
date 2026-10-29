<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\Module;
use App\Services\ModuleCollisionGuard;
use App\Support\ModuleDependencySorter;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Discovers, loads, validates, and sorts module manifests across core, custom, and vendor directories.
 */
class ModuleManifestLoader
{
    public const CACHE_PATH = 'bootstrap/cache/modules.php';

    public function __construct(private readonly ?string $basePath = null) {}

    /**
     * Discover all installed modules by scanning the filesystem or loading cache.
     *
     * @return array<string, array<string, mixed>>
     */
    public function discoverAll(?string $basePath = null): array
    {
        $base = $basePath ?? $this->basePath ?? base_path();
        $cachedPath = $base.'/'.self::CACHE_PATH;

        if (is_file($cachedPath)) {
            $modules = require $cachedPath;

            foreach ($modules as &$module) {
                $module['source'] ??= 'core';
            }
            unset($module);

            $this->validateModuleNames($modules);
            $this->checkDependencies($modules);

            return $modules;
        }

        $modules = $this->scanDirectory($base.'/app-modules/*/module.json', 'core');
        $this->mergeDiscovered($modules, $this->scanDirectory($base.'/custom-modules/*/module.json', 'custom'));
        $this->mergeDiscovered($modules, $this->scanDirectory($base.'/vendor/*/*/module.json', 'vendor'));

        $this->validateModuleNames($modules);
        $this->checkDependencies($modules);

        return ModuleDependencySorter::sort($modules);
    }

    /**
     * Merge discovered manifests into the module set, rejecting name collisions.
     *
     * @param  array<string, array<string, mixed>>  $modules
     * @param  array<string, array<string, mixed>>  $candidates
     */
    public function mergeDiscovered(array &$modules, array $candidates): void
    {
        $guard = new ModuleCollisionGuard;
        $establishedNames = array_keys($modules);

        foreach ($candidates as $name => $manifest) {
            if (! $guard->assertNoNameCollision($name, $establishedNames, (string) ($manifest['source'] ?? 'custom'))) {
                continue;
            }

            $modules[$name] = $manifest;
        }
    }

    /**
     * Scan a glob pattern for module.json files and return parsed manifests.
     *
     * @return array<string, array<string, mixed>>
     */
    public function scanDirectory(string $pattern, string $source): array
    {
        $modules = [];

        foreach (glob($pattern) ?: [] as $path) {
            $manifest = $this->loadManifest($path);

            if ($manifest === null) {
                continue;
            }

            $manifest['source'] = $source;
            $manifest['path'] = dirname($path);

            $name = $manifest['name'];
            $modules[$name] = $manifest;
        }

        return $modules;
    }

    /**
     * Load and validate a single module.json file.
     *
     * @return array<string, mixed>|null
     */
    public function loadManifest(string $path): ?array
    {
        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $manifest = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("Module manifest [{$path}] contains invalid JSON.");

            return null;
        }

        $required = ['name', 'version', 'namespace', 'display_name'];

        foreach ($required as $field) {
            if (! isset($manifest[$field]) || (is_string($manifest[$field]) && trim($manifest[$field]) === '')) {
                Log::warning("Module manifest [{$path}] is missing required field: [{$field}].");

                return null;
            }
        }

        if (! preg_match('/^[a-z][a-z0-9-]*$/', $manifest['name'])) {
            Log::warning("Module manifest [{$path}] has invalid name: must start with a lowercase letter, followed by lowercase alphanumeric characters or hyphens (kebab-case).");

            return null;
        }

        return $manifest;
    }

    /**
     * Reject custom modules whose declared name collides with a core module.
     *
     * @param  array<string, array<string, mixed>>  $modules
     */
    public function validateModuleNames(array &$modules): void
    {
        $guard = new ModuleCollisionGuard;
        $coreNames = [];

        foreach ($modules as $name => $manifest) {
            if (($manifest['source'] ?? 'core') === 'core') {
                $coreNames[] = $name;
            }
        }

        foreach ($modules as $name => $manifest) {
            if (($manifest['source'] ?? 'core') !== 'custom') {
                continue;
            }

            if (! $guard->assertNoNameCollision($name, $coreNames)) {
                unset($modules[$name]);
            }
        }
    }

    /**
     * Check inter-module and Composer-package dependency requirements, failing soft.
     *
     * @param  array<string, array<string, mixed>>  $modules
     */
    public function checkDependencies(array &$modules): void
    {
        do {
            $changed = false;

            foreach ($modules as $name => &$manifest) {
                if (($manifest['enabled'] ?? true) === false) {
                    continue;
                }

                $reasons = $this->unmetRequirementReasons($manifest, $modules);

                if ($reasons === []) {
                    continue;
                }

                $manifest['enabled'] = false;
                $changed = true;

                if ($this->registryRecordsDisabled($name)) {
                    continue;
                }

                if (($manifest['required'] ?? false) === true) {
                    Log::critical(sprintf(
                        'Required module [%s] failed its boot dependency check and was disabled: %s. This strands critical functionality — install the missing requirements and re-enable the module from /panel/modules immediately.',
                        $name,
                        implode('; ', $reasons),
                    ));
                } else {
                    Log::critical(sprintf(
                        'Module [%s] failed its boot dependency check and was disabled: %s. After installing the missing requirements, re-enable the module from /panel/modules and clear caches with php artisan optimize:clear.',
                        $name,
                        implode('; ', $reasons),
                    ));
                }

                $this->markRegistryDisabled($name, $manifest);
            }

            unset($manifest);
        } while ($changed);
    }

    /**
     * Check whether the registry already records a module as boot-disabled.
     */
    private function registryRecordsDisabled(string $name): bool
    {
        try {
            if (! Schema::hasTable('modules')) {
                return false;
            }

            return Module::query()
                ->where('name', $name)
                ->where('enabled', false)
                ->where('status', Module::StatusDisabled)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Collect the unmet requirement reasons for one module.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array<string, mixed>>  $modules
     * @return array<int, string>
     */
    public function unmetRequirementReasons(array $manifest, array $modules): array
    {
        $reasons = [];

        foreach ($manifest['requirements']['modules'] ?? [] as $dep) {
            $depName = is_string($dep) ? $dep : ($dep['name'] ?? null);
            $depVersion = is_string($dep) ? '*' : ($dep['version'] ?? '*');

            if ($depName === null) {
                continue;
            }

            if (! isset($modules[$depName]) || (($modules[$depName]['enabled'] ?? true) === false)) {
                $reasons[] = "required module [{$depName}] is not available";

                continue;
            }

            $installedVersion = (string) ($modules[$depName]['version'] ?? '');

            if (! $this->satisfiesVersion($installedVersion, $depVersion)) {
                $reasons[] = "required module [{$depName}] version [{$depVersion}], but [{$installedVersion}] is installed";
            }
        }

        foreach ((array) ($manifest['requirements']['packages'] ?? []) as $package) {
            $packageName = is_string($package) ? $package : ($package['name'] ?? null);

            if ($packageName === null) {
                continue;
            }

            if (! $this->composerPackageInstalled($packageName)) {
                $reasons[] = "Composer package [{$packageName}] is not installed";
            }
        }

        return $reasons;
    }

    /**
     * Check whether a Composer package is installed in the running vendor tree.
     */
    private function composerPackageInstalled(string $package): bool
    {
        if (! class_exists(InstalledVersions::class)) {
            return true;
        }

        try {
            return InstalledVersions::isInstalled($package);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Record a boot-time dependency failure in the modules registry.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function markRegistryDisabled(string $name, array $manifest): void
    {
        try {
            if (! Schema::hasTable('modules')) {
                return;
            }

            Module::query()->updateOrCreate(
                ['name' => $name],
                [
                    'enabled' => false,
                    'status' => Module::StatusDisabled,
                    'display_name' => $manifest['display_name'] ?? $name,
                    'version' => $manifest['version'] ?? null,
                    'source' => $manifest['source'] ?? 'core',
                ],
            );
        } catch (\Throwable) {
            // Registry unavailable during early boot — fail soft.
        }
    }

    /**
     * Simple version constraint check (supports >=, ~, ^, exact).
     */
    public function satisfiesVersion(string $installed, string $constraint): bool
    {
        if ($constraint === '*' || $constraint === '') {
            return true;
        }

        if (str_starts_with($constraint, '>=')) {
            return version_compare($installed, substr($constraint, 2), '>=');
        }

        if (str_starts_with($constraint, '~')) {
            $lower = substr($constraint, 1);
            $parts = explode('.', $lower);

            $ceiling = count($parts) === 1
                ? ((int) $parts[0] + 1).'.0.0'
                : $parts[0].'.'.((int) $parts[1] + 1).'.0';

            return version_compare($installed, $lower, '>=')
                && version_compare($installed, $ceiling, '<');
        }

        if (str_starts_with($constraint, '^')) {
            $lower = substr($constraint, 1);
            $parts = explode('.', $lower);

            if ($parts[0] !== '0') {
                $nextBreaking = ((int) $parts[0] + 1).'.0.0';
            } elseif (isset($parts[1]) && $parts[1] !== '0') {
                $nextBreaking = '0.'.((int) $parts[1] + 1).'.0';
            } elseif (isset($parts[1]) && $parts[1] === '0' && isset($parts[2])) {
                $nextBreaking = '0.0.'.((int) $parts[2] + 1);
            } else {
                $nextBreaking = '1.0.0';
            }

            return version_compare($installed, $lower, '>=')
                && version_compare($installed, $nextBreaking, '<');
        }

        return version_compare($installed, $constraint, '==');
    }
}
