<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Modules\ModuleManifestLoader;
use App\Support\CustomModuleAutoloader;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the module discovery and registration system.
 *
 * Scans module.json files across app-modules/, custom-modules/, and vendor/ directories,
 * validates them, and registers enabled module service providers dynamically.
 */
class ModuleServiceProvider extends ServiceProvider
{
    private const CACHE_PATH = ModuleManifestLoader::CACHE_PATH;

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
     * Discover all installed modules by scanning the filesystem or loading cache.
     *
     * Delegates to ModuleManifestLoader.
     *
     * @return array<string, array<string, mixed>>
     */
    private function discoverModules(): array
    {
        return (new ModuleManifestLoader)->discoverAll();
    }

    /**
     * Simple version constraint check (supports >=, ~, ^, exact).
     *
     * Preserved for reflection and backward compatibility; delegates to ModuleManifestLoader.
     */
    private function satisfiesVersion(string $installed, string $constraint): bool
    {
        return (new ModuleManifestLoader)->satisfiesVersion($installed, $constraint);
    }

    /**
     * Load and validate a single module.json file.
     *
     * Preserved for reflection and backward compatibility; delegates to ModuleManifestLoader.
     */
    private function loadManifest(string $path): ?array
    {
        return (new ModuleManifestLoader)->loadManifest($path);
    }
}
