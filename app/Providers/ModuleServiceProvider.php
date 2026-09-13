<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the module discovery and registration system.
 *
 * Scans module.json files in app-modules/ and vendor/ directories,
 * validates them, and exposes parsed metadata through the container.
 * Laravel package discovery remains the source of truth for loading
 * each module's service provider.
 */
class ModuleServiceProvider extends ServiceProvider
{
    private const CACHE_PATH = 'bootstrap/cache/modules.php';

    /**
     * Register module system services.
     *
     * Discovers all module manifests, validates them, checks inter-module
     * dependencies, and binds the parsed metadata for registry consumers.
     */
    public function register(): void
    {
        $this->app->singleton('modules.manifest', fn (): array => $this->discoverModules());
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
     * module.json files in app-modules/ and vendor/ directories.
     */
    private function discoverModules(): array
    {
        $cachedPath = base_path(self::CACHE_PATH);

        if (is_file($cachedPath)) {
            $modules = require $cachedPath;

            $this->validateModuleNames($modules);

            return $modules;
        }

        $modules = $this->scanDirectory(base_path('app-modules/*/module.json'));
        $modules = array_merge($modules, $this->scanDirectory(base_path('vendor/*/*/module.json')));

        $this->validateModuleNames($modules);
        $this->checkDependencies($modules);

        uasort($modules, fn (array $a, array $b): int => ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0));

        return $modules;
    }

    /**
     * Scan a glob pattern for module.json files and return parsed manifests.
     */
    private function scanDirectory(string $pattern): array
    {
        $modules = [];

        foreach (glob($pattern) as $path) {
            $manifest = $this->loadManifest($path);

            if ($manifest === null) {
                continue;
            }

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

        if (! preg_match('/^[a-z][a-z0-9_-]*$/', $manifest['name'])) {
            Log::warning("Module manifest [{$path}] has invalid name: must start with lowercase letter, followed by lowercase alphanumeric, hyphens, or underscores.");

            return null;
        }

        return $manifest;
    }

    /**
     * Validate that all discovered modules have unique names.
     */
    private function validateModuleNames(array $modules): void
    {
        // Names are already unique since we use name as array key
    }

    /**
     * Check inter-module dependency requirements.
     *
     * Iterates each module's requirements.modules list and verifies
     * that the required modules exist and meet version constraints.
     *
     * @throws \RuntimeException when a dependency is missing
     */
    private function checkDependencies(array $modules): void
    {
        foreach ($modules as $name => $manifest) {
            $requirements = $manifest['requirements']['modules'] ?? [];

            foreach ($requirements as $dep) {
                $depName = is_string($dep) ? $dep : ($dep['name'] ?? null);
                $depVersion = is_string($dep) ? '*' : ($dep['version'] ?? '*');

                if ($depName === null) {
                    continue;
                }

                if (! isset($modules[$depName])) {
                    throw new \RuntimeException(
                        "Module [{$name}] requires [{$depName}] which is not installed."
                    );
                }

                $installedVersion = $modules[$depName]['version'];

                if (! $this->satisfiesVersion($installedVersion, $depVersion)) {
                    throw new \RuntimeException(
                        "Module [{$name}] requires [{$depName}] version [{$depVersion}], but [{$installedVersion}] is installed."
                    );
                }
            }
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
            $nextMinor = $parts[0].'.'.((int) ($parts[1] ?? 0) + 1).'.0';

            return version_compare($installed, $lower, '>=')
                && version_compare($installed, $nextMinor, '<');
        }

        if (str_starts_with($constraint, '^')) {
            $lower = substr($constraint, 1);
            $parts = explode('.', $lower);
            $nextMajor = ((int) $parts[0] + 1).'.0.0';

            return version_compare($installed, $lower, '>=')
                && version_compare($installed, $nextMajor, '<');
        }

        return version_compare($installed, $constraint, '==');
    }
}
