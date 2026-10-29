<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Module;
use App\Services\ModuleCollisionGuard;
use App\Support\CustomModuleAutoloader;
use App\Support\ModuleDependencySorter;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the module discovery and registration system.
 *
 * Scans module.json files in app-modules/, custom-modules/, and vendor/ directories,
 * validates them, and registers enabled module service providers dynamically.
 */
class ModuleServiceProvider extends ServiceProvider
{
    private const CACHE_PATH = 'bootstrap/cache/modules.php';

    /**
     * Register module system services.
     *
     * Discovers all module manifests, initializes the custom module autoloader,
     * binds parsed metadata, and dynamically registers service providers for enabled modules.
     */
    public function register(): void
    {
        CustomModuleAutoloader::register();

        $modules = $this->discoverModules();

        // Register custom module namespaces in dynamic autoloader
        foreach ($modules as $manifest) {
            if (($manifest['source'] ?? null) === 'custom' && ! empty($manifest['namespace']) && ! empty($manifest['path'])) {
                CustomModuleAutoloader::addModule($manifest['namespace'], $manifest['path'].'/src');
            }
        }

        $this->app->singleton('modules.manifest', fn (): array => $modules);

        foreach ($modules as $name => $manifest) {
            // Fail-soft propagation: modules disabled at boot by the dependency
            // check keep out of the registration path entirely (plan §1.7.6).
            if (($manifest['enabled'] ?? true) === false) {
                continue;
            }

            foreach (self::providersFor($manifest) as $providerClass) {
                if (class_exists($providerClass)) {
                    $this->app->register($providerClass);
                }
            }
        }
    }

    /**
     * Resolve the service provider classes a manifest declares.
     *
     * Returns the manifest's explicit providers list; when the manifest omits
     * one, falls back to the conventional `{namespace}\Providers\ModuleServiceProvider`
     * class when it exists, and to an empty list otherwise.
     */
    public static function providersFor(array $manifest): array
    {
        $providers = $manifest['providers'] ?? [];

        // Guard against malformed manifests: a non-array providers value must
        // fall through to the convention (or nothing) rather than fatal boot.
        if (is_array($providers) && $providers !== []) {
            return $providers;
        }

        // Convention fallback: modules may omit the providers key, in which
        // case the standard src/Providers/ModuleServiceProvider.php layout is
        // used when that class exists.
        $namespace = $manifest['namespace'] ?? '';

        if ($namespace !== '') {
            $fallback = $namespace.'\\Providers\\ModuleServiceProvider';

            if (class_exists($fallback)) {
                return [$fallback];
            }
        }

        return [];
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Discover all installed modules by scanning the filesystem.
     *
     * Returns cached manifests when available; otherwise scans
     * module.json files in app-modules/, custom-modules/, and vendor/ directories.
     * Both paths run the same collision and fail-soft guards so boot behavior
     * never depends on cache state (plan §1.7 / Task 4).
     */
    private function discoverModules(): array
    {
        $cachedPath = base_path(self::CACHE_PATH);

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

        $modules = $this->scanDirectory(base_path('app-modules/*/module.json'), 'core');
        $this->mergeDiscovered($modules, $this->scanDirectory(base_path('custom-modules/*/module.json'), 'custom'));
        $this->mergeDiscovered($modules, $this->scanDirectory(base_path('vendor/*/*/module.json'), 'vendor'));

        $this->validateModuleNames($modules);
        $this->checkDependencies($modules);

        return ModuleDependencySorter::sort($modules);
    }

    /**
     * Merge discovered manifests into the module set, rejecting name collisions.
     *
     * Discovery order establishes ownership: core beats custom beats vendor.
     * A later-source module whose name would shadow an installed module is
     * rejected with a descriptive logged error instead of being silently
     * dropped (plan §1.7.1).
     *
     * @param  array<string, array<string, mixed>>  $modules  Discovered set, modified in place.
     * @param  array<string, array<string, mixed>>  $candidates  Manifests from the next source directory.
     */
    private function mergeDiscovered(array &$modules, array $candidates): void
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
     */
    private function scanDirectory(string $pattern, string $source): array
    {
        $modules = [];

        foreach (glob($pattern) as $path) {
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
     */
    private function loadManifest(string $path): ?array
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
     * The merge step already rejects collisions with a logged error; this
     * second pass re-checks the assembled set (including cached manifests
     * written before the guard existed) so a shadowing custom entry can never
     * reach registration.
     */
    private function validateModuleNames(array &$modules): void
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
     * A module with unmet requirements is marked disabled in its manifest
     * entry (register() honors the mark and skips its providers) and its
     * registry row is synced so ModuleState agrees with the manifest. The
     * check cascades so dependents of a disabled module are disabled too,
     * and the appliance never crashes at boot because of a broken
     * extension (§1.7.6).
     */
    private function checkDependencies(array &$modules): void
    {
        // Repeat passes until the disabled set is stable: a dependent
        // processed before its dependency must still see the dependency's
        // disabled mark on the next pass.
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

                // Deduplicate across boots: once the registry already
                // records the failure there is nothing new to report, and
                // re-logging or re-writing on every request would flood the
                // log and hammer the registry row.
                if ($this->registryRecordsDisabled($name)) {
                    continue;
                }

                // Required modules strand core functionality when disabled,
                // so their failure is logged with an unmistakable warning.
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
     *
     * Used to skip repeated critical logs and registry writes on every boot
     * of an already-disabled broken module.
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
     * Verifies requirements.modules against the discovered set (including
     * version constraints) and requirements.packages against the installed
     * Composer packages.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array<string, mixed>>  $modules
     * @return array<int, string>
     */
    private function unmetRequirementReasons(array $manifest, array $modules): array
    {
        $reasons = [];

        foreach ($manifest['requirements']['modules'] ?? [] as $dep) {
            $depName = is_string($dep) ? $dep : ($dep['name'] ?? null);
            $depVersion = is_string($dep) ? '*' : ($dep['version'] ?? '*');

            if ($depName === null) {
                continue;
            }

            if (! isset($modules[$depName]) || (($modules[$depName]['enabled'] ?? true) === false)) {
                // A disabled dependency never registered its providers, so a
                // dependent that stayed enabled would crash at runtime.
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
        // Without the Composer runtime there is no package metadata to trust;
        // assume installed rather than disabling modules on stripped runtimes.
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
     * Writes enabled = false and status = disabled so ModuleState agrees with
     * the manifest. Silently skipped when the registry is unavailable during
     * very early boot (pre-migrate installers): the manifest mark alone still
     * blocks provider registration.
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
    private function satisfiesVersion(string $installed, string $constraint): bool
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

            // The ~ constraint accepts the current minor line only:
            // ~1.2 means >= 1.2 and < 1.3. A bare major (~1) has no minor
            // to pin, so it spans the whole major line: >= 1 and < 2.0.
            $ceiling = count($parts) === 1
                ? ((int) $parts[0] + 1).'.0.0'
                : $parts[0].'.'.((int) $parts[1] + 1).'.0';

            return version_compare($installed, $lower, '>=')
                && version_compare($installed, $ceiling, '<');
        }

        if (str_starts_with($constraint, '^')) {
            $lower = substr($constraint, 1);
            $parts = explode('.', $lower);
            // The ^ constraint accepts everything below the next breaking version.
            // Under Composer semantics:
            // - ^1.2   => >= 1.2   < 2.0.0
            // - ^0.2.3 => >= 0.2.3 < 0.3.0
            // - ^0.0.3 => >= 0.0.3 < 0.0.4
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
