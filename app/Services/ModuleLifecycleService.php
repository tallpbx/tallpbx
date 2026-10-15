<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ModuleUninstaller;
use App\Models\Module;
use App\Models\Permission;
use Closure;
use Database\Seeders\AdminSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Manages the destructive module lifecycle under the modular-monolith rules.
 *
 * First-party core modules (app-modules/) are permanent monorepo code and can
 * never be uninstalled — administrators disable them instead. Third-party
 * custom modules (custom-modules/) are untracked extensions that can be
 * deleted with typed confirmation; their registry row is removed because
 * nothing on disk or in git can bring them back. Vendor modules are removed
 * and restored through Composer, keeping a registry marker with the package
 * name so module:restore can re-require them later. Database data is
 * intentionally not restored.
 */
class ModuleLifecycleService
{
    /**
     * Create the lifecycle service.
     *
     * The base path and process runner are injectable so tests can run the
     * service against a hermetic sandbox instead of the real installation.
     *
     * @param  Closure(array<int, string>): bool|null  $composerRunner  test seam that performs Composer operations
     */
    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
        private readonly ?string $basePath = null,
        private readonly ?Closure $composerRunner = null,
    ) {}

    // ─── Uninstall ──────────────────────────────────────────────────────

    /**
     * Build the exact phrase required to destructively uninstall a module.
     */
    public function confirmationPhrase(string $name): string
    {
        return 'UNINSTALL '.$name;
    }

    /**
     * Return a human-readable uninstall preview for a module.
     *
     * @return array<string, mixed>
     */
    public function previewUninstall(string $name): array
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            return $this->refusal('Invalid module name.');
        }

        // Discover which lifecycle class the module belongs to: permanent
        // core code, an untracked custom extension, or a vendor package.
        $coreDir = $this->coreDir($name);
        $customDir = $coreDir === null ? $this->customDir($name) : null;
        $vendor = ($coreDir === null && $customDir === null) ? $this->vendorModuleInfo($name) : null;

        if ($coreDir !== null) {
            return $this->refusal('Built-in core modules cannot be uninstalled. Use the disable toggle instead.');
        }

        if ($customDir === null && $vendor === null) {
            return $this->refusal("Module [{$name}] is not an installed custom extension or vendor package.");
        }

        $source = $customDir !== null ? 'custom' : 'vendor';
        $dir = $customDir ?? ($vendor['dir'] ?? '');

        $manifest = $this->manifestFor($dir);

        if ($manifest === null) {
            return $this->refusal("Module [{$name}] has no readable module.json manifest.");
        }

        $registryRow = Module::where('name', $name)->first();

        // Both the on-disk manifest and the registry row carry protection
        // flags; either one refusing is enough to refuse removal.
        if (($manifest['protected'] ?? false) || ($manifest['required'] ?? false)
            || ($registryRow?->protected ?? false) || ($registryRow?->required ?? false)) {
            return $this->refusal('Required and protected modules cannot be uninstalled.');
        }

        $uninstallItems = [];
        $uninstallAvailable = false;

        // Only drop data when a handler exists; otherwise tables are left
        // alone and the operator is warned below.
        if ($registryRow !== null) {
            $uninstaller = $this->uninstallerFor($name);

            if ($uninstaller !== null && $uninstaller->canUninstall($registryRow)) {
                $uninstallAvailable = true;
                $uninstallItems = $uninstaller->previewUninstall($registryRow);
            }
        }

        $dependents = $this->installedDependents($name);

        if ($dependents !== []) {
            return $this->refusal('Modules ['.implode(', ', $dependents).'] require this module; uninstall them first.');
        }

        $warnings = [];
        $this->appendCrossTestWarnings($name, $warnings);

        if (! $uninstallAvailable) {
            $warnings[] = "Module [{$name}] has no uninstall handler; its database tables (if any) will be left intact.";
        }

        if ($source === 'custom') {
            // Custom modules are untracked, so nothing can bring them back.
            $warnings[] = 'This custom extension cannot be restored after deletion — no copy exists in git or Composer.';
        }

        return [
            'can_uninstall' => true,
            'reason' => null,
            'module_kind' => $source,
            'module_dir' => $dir,
            'vendor_package' => $source === 'vendor' ? ($vendor['package'] ?? null) : null,
            'registry_row' => $registryRow !== null,
            'permission_count' => Permission::where('module', $name)->count(),
            'uninstall_items' => $uninstallItems,
            'uninstall_available' => $uninstallAvailable,
            'restore_hint' => $source === 'vendor'
                ? 'Restore later with: php artisan module:restore '.$name.' (Composer re-require; database data is not restored)'
                : '',
            'display_name' => $manifest['display_name'] ?? $name,
            'version' => $manifest['version'] ?? '0.0.0',
            'warnings' => $warnings,
        ];
    }

    /**
     * Permanently uninstall a module after an exact confirmation phrase match.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function uninstall(string $name, string $confirmation): array
    {
        $preview = $this->previewUninstall($name);

        if (! $preview['can_uninstall']) {
            throw ValidationException::withMessages(['module' => $preview['reason'] ?? 'This module cannot be uninstalled.']);
        }

        if ($confirmation !== $this->confirmationPhrase($name)) {
            throw ValidationException::withMessages(['confirmation' => 'The confirmation phrase did not match.']);
        }

        $isVendor = $preview['module_kind'] === 'vendor';

        // Vendor modules are removed through Composer first: the package must
        // stay resolvable until Composer has cleaned its own autoloader and
        // lockfile, and a failed removal leaves everything untouched.
        $composerRemoved = false;

        if ($isVendor) {
            $composerRemoved = $this->runComposer(['remove', (string) $preview['vendor_package'], '--no-interaction']);

            if (! $composerRemoved) {
                throw ValidationException::withMessages([
                    'module' => "Composer could not remove [{$preview['vendor_package']}]. The module was left untouched so the installation stays bootable; fix Composer and retry.",
                ]);
            }
        }

        $uninstallRan = false;
        $permissionsDeleted = Permission::where('module', $name)->count();

        DB::transaction(function () use ($name, $preview, &$uninstallRan): void {
            // Drop module-owned data first when the module provides a handler.
            if ($preview['uninstall_available']) {
                $this->uninstallerFor($name)?->uninstall(Module::where('name', $name)->firstOrFail());
                $uninstallRan = true;
            }

            // Permission rows always belong to the uninstalled module.
            Permission::query()->where('module', $name)->delete();
        });

        $moduleDirDeleted = false;

        if (! $isVendor) {
            // Custom extensions own their untracked directory; nothing else
            // in the installation tree belongs to them.
            $moduleDirDeleted = $this->files->deleteDirectory((string) $preview['module_dir']);
        }

        // Surface any cleanup step that did not complete, so a partial
        // uninstall is never presented to the operator as a clean one.
        $cleanupWarnings = [];

        if (! $isVendor && ! $moduleDirDeleted) {
            $cleanupWarnings[] = "The module directory [{$preview['module_dir']}] could not be deleted; please remove it manually and retry the deletion.";
        }

        if ($isVendor) {
            // Keep a minimal registry marker so restore can re-require the
            // package later.
            $this->recordUninstallMarker($name, $preview);
        } elseif ($moduleDirDeleted) {
            // Custom modules have no origin to restore from, so their registry
            // row is deleted along with their files. When the directory
            // deletion failed, keep the row: deleting it would re-register a
            // default-enabled, panel-visible extension on the next boot.
            Module::where('name', $name)->delete();
        }

        // Clear caches so stale provider/view/route caches do not linger.
        // During automated tests, skip optimize:clear so we do not wipe in-flight
        // compiled Blade views across concurrent parallel test workers.
        if (! app()->runningUnitTests()) {
            Artisan::call('optimize:clear');
        }

        Log::notice('Module uninstalled.', ['module' => $name, 'source' => $preview['module_kind']]);

        return [
            'module_kind' => $preview['module_kind'],
            'module_dir_deleted' => $moduleDirDeleted,
            'composer_package_removed' => $composerRemoved,
            'permissions_deleted' => $permissionsDeleted,
            'uninstall_ran' => $uninstallRan,
            'restore_hint' => $preview['restore_hint'],
            'warnings' => array_merge($preview['warnings'], $cleanupWarnings),
        ];
    }

    // ─── Restore ────────────────────────────────────────────────────────

    /**
     * Return a human-readable restore plan for a previously uninstalled module.
     *
     * Restore survives only for vendor modules: Composer can re-require their
     * package. Core modules are permanent monorepo code and can never be
     * uninstalled, and custom modules are untracked with no origin to restore
     * from.
     *
     * @return array<string, mixed>
     */
    public function previewRestore(string $name): array
    {
        $empty = [
            'can_restore' => false,
            'reason' => null,
            'source' => null,
            'module_dir' => '',
            'composer_package' => '',
            'registry_row' => false,
            'items' => [],
            'data_notice' => '',
        ];

        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            return array_merge($empty, ['reason' => 'Invalid module name.']);
        }

        $registryRow = Module::where('name', $name)->first();

        // Restore only applies to a module that was actually uninstalled.
        // Touching a module that is still installed would overwrite its live
        // files and force it back on, so refuse before anything else happens.
        if ($registryRow !== null && $registryRow->status !== Module::StatusUninstalled) {
            return array_merge($empty, [
                'reason' => "Module [{$name}] is still installed; only uninstalled modules can be restored.",
                'registry_row' => true,
            ]);
        }

        if ($registryRow === null && ($this->coreDir($name) !== null || $this->customDir($name) !== null || $this->vendorModuleInfo($name) !== null)) {
            return array_merge($empty, [
                'reason' => "Module [{$name}] is still installed; it was never marked uninstalled, so there is nothing to restore.",
            ]);
        }

        if ($registryRow === null || $registryRow->composer_package === null || $registryRow->composer_package === '') {
            return array_merge($empty, [
                'reason' => "Module [{$name}] has no recorded Composer package to restore from. Custom extensions are untracked and cannot be restored.",
                'registry_row' => $registryRow !== null,
            ]);
        }

        return [
            'can_restore' => true,
            'reason' => null,
            'source' => 'composer',
            'module_dir' => $registryRow->composer_package,
            'composer_package' => $registryRow->composer_package,
            'registry_row' => true,
            'items' => [
                "Reinstall the Composer package: {$registryRow->composer_package}",
            ],
            'data_notice' => 'The module returns with empty tables; uninstalled data is not restored.',
        ];
    }

    /**
     * Restore a previously uninstalled vendor module through Composer.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function restore(string $name): array
    {
        $preview = $this->previewRestore($name);

        if (! $preview['can_restore']) {
            throw ValidationException::withMessages(['module' => $preview['reason'] ?? 'This module cannot be restored.']);
        }

        $restored = $this->runComposer(['require', $preview['composer_package'], '--no-interaction']);

        if (! $restored) {
            throw ValidationException::withMessages([
                'module' => "Composer could not reinstall [{$preview['composer_package']}].",
            ]);
        }

        // Refresh the registry row from the restored manifest and re-enable it.
        $this->refreshRegistryRow($name);

        // Recreate the module's tables (empty) by re-running its migrations.
        $this->runModuleMigrations($name);

        // Re-seed permission rows for every installed module (idempotent).
        Artisan::call('db:seed', ['--class' => AdminSeeder::class, '--force' => true]);

        // Clear caches so newly restored providers, views, and routes are discovered.
        // During automated tests, skip optimize:clear to protect concurrent test workers.
        if (! app()->runningUnitTests()) {
            Artisan::call('optimize:clear');
        }

        Log::notice('Module restored.', ['module' => $name]);

        return [
            'source' => $preview['source'],
            'files_restored' => $restored,
            'data_notice' => $preview['data_notice'],
        ];
    }

    // ─── Private helpers ────────────────────────────────────────────────

    /**
     * Build a preview that refuses uninstall with a human-readable reason.
     *
     * @return array<string, mixed>
     */
    private function refusal(string $reason): array
    {
        return [
            'can_uninstall' => false,
            'reason' => $reason,
            'module_kind' => null,
            'module_dir' => null,
            'vendor_package' => null,
            'registry_row' => false,
            'permission_count' => 0,
            'uninstall_items' => [],
            'uninstall_available' => false,
            'restore_hint' => '',
            'display_name' => '',
            'version' => '',
            'warnings' => [],
        ];
    }

    /**
     * Resolve the working root for paths, falling back to the real project.
     */
    private function basePath(): string
    {
        return $this->basePath ?? base_path();
    }

    /**
     * Resolve the permanent core module directory for a module name.
     */
    private function coreDir(string $name): ?string
    {
        $dir = $this->basePath()."/app-modules/{$name}";

        return is_dir($dir) ? $dir : null;
    }

    /**
     * Resolve the untracked custom extension directory for a module name.
     */
    private function customDir(string $name): ?string
    {
        $dir = $this->basePath()."/custom-modules/{$name}";

        return is_dir($dir) ? $dir : null;
    }

    /**
     * Resolve a vendor-installed package that provides the given module name.
     *
     * @return array{package: string, dir: string}|null
     */
    private function vendorModuleInfo(string $name): ?array
    {
        foreach (glob($this->basePath().'/vendor/*/*/module.json') ?: [] as $path) {
            $manifest = $this->manifestFor(dirname($path));

            if ($manifest === null || ($manifest['name'] ?? null) !== $name) {
                continue;
            }

            // Composer lays packages out as vendor/{vendor-name}/{package-name}.
            $segments = explode('/', ltrim(str_replace($this->basePath(), '', dirname($path)), '/'));

            if (count($segments) !== 3) {
                continue;
            }

            return ['package' => $segments[1].'/'.$segments[2], 'dir' => dirname($path)];
        }

        return null;
    }

    /**
     * Read and validate a module's manifest file.
     *
     * @return array<string, mixed>|null
     */
    private function manifestFor(string $dir): ?array
    {
        $contents = @file_get_contents("{$dir}/module.json");

        if ($contents === false) {
            return null;
        }

        $manifest = json_decode($contents, true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * Find a tagged uninstaller for the given module name.
     */
    private function uninstallerFor(string $module): ?ModuleUninstaller
    {
        foreach ($this->app->tagged('module.uninstallers') as $uninstaller) {
            if ($uninstaller instanceof ModuleUninstaller && $uninstaller->moduleName() === $module) {
                return $uninstaller;
            }
        }

        return null;
    }

    /**
     * Run a Composer command, or the injected test seam.
     *
     * @param  array<int, string>  $arguments
     */
    private function runComposer(array $arguments): bool
    {
        if ($this->composerRunner !== null) {
            return ($this->composerRunner)($arguments);
        }

        $process = new Process(array_merge(['composer'], $arguments), $this->basePath());
        $process->setTimeout(600);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Keep or create a registry marker for an uninstalled vendor module so
     * the restore command knows which Composer package to re-require.
     *
     * @param  array<string, mixed>  $preview
     */
    private function recordUninstallMarker(string $name, array $preview): void
    {
        $attributes = [
            'enabled' => false,
            'status' => Module::StatusUninstalled,
            'composer_package' => $preview['vendor_package'],
        ];

        $registryRow = Module::where('name', $name)->first();

        if ($registryRow !== null) {
            $registryRow->update($attributes);

            return;
        }

        // A module that was never synced still needs a marker so restore can
        // recover its origin (the vendor package name).
        Module::create(array_merge([
            'name' => $name,
            'display_name' => $preview['display_name'],
            'version' => $preview['version'],
        ], $attributes));
    }

    /**
     * Sync the registry row from the restored on-disk manifest and re-enable it.
     */
    private function refreshRegistryRow(string $name): void
    {
        $dir = $this->vendorModuleInfo($name)['dir'] ?? null;

        $manifest = $dir !== null ? $this->manifestFor($dir) : null;

        Module::updateOrCreate(
            ['name' => $name],
            [
                'display_name' => $manifest['display_name'] ?? $name,
                'version' => $manifest['version'] ?? '0.0.0',
                'enabled' => true,
                'status' => Module::StatusEnabled,
                'protected' => $manifest['protected'] ?? false,
                'required' => $manifest['required'] ?? false,
                'priority' => $manifest['priority'] ?? 0,
            ],
        );
    }

    /**
     * Resolve the installed modules that declare a requirement on this module.
     *
     * @return array<int, string>
     */
    private function installedDependents(string $name): array
    {
        $dependents = [];

        foreach (array_merge(
            glob($this->basePath().'/app-modules/*/module.json') ?: [],
            glob($this->basePath().'/custom-modules/*/module.json') ?: [],
            glob($this->basePath().'/vendor/*/*/module.json') ?: [],
        ) as $path) {
            $manifest = $this->manifestFor(dirname($path));

            if ($manifest === null || ($manifest['name'] ?? null) === $name) {
                continue;
            }

            $deps = $manifest['requirements']['modules'] ?? [];

            foreach ($deps as $dependency) {
                // Fail-safe parsing: recognize both the canonical string form
                // and the legacy object form, so a manifest written against an
                // older schema can never hide a dependent and silently defeat
                // the uninstall guard.
                $dependencyName = is_array($dependency) ? ($dependency['name'] ?? null) : $dependency;

                if ($dependencyName === $name) {
                    $dependents[] = $manifest['name'];
                    break;
                }
            }
        }

        return $dependents;
    }

    /**
     * Warn about centralized tests outside the removed module's own folder
     * that still reference its classes.
     *
     * @param  array<int, string>  $warnings
     */
    private function appendCrossTestWarnings(string $name, array &$warnings): void
    {
        $studly = Str::studly($name);
        $needle = "Modules\\{$studly}\\";
        $ownDirs = [
            $this->basePath()."/tests/Feature/Modules/{$studly}",
            $this->basePath()."/custom-modules/{$name}",
            $this->basePath()."/app-modules/{$name}",
        ];
        $hits = [];

        // Cross-module references can live in the central tests tree OR inside
        // another module's own tests — scan both.
        $scanRoots = array_filter([
            $this->basePath().'/tests',
            $this->basePath().'/app-modules',
            $this->basePath().'/custom-modules',
        ], 'is_dir');

        foreach ($this->files->allFiles($scanRoots) as $file) {
            $path = $file->getPathname();

            // Dependency trees and VCS/scratch directories are never part of
            // the installation's own module or test code; scanning them would
            // surface vendored copies of the same namespace as false
            // cross-module hits.
            if (preg_match('#/(vendor|node_modules)/#', $path) === 1 || str_contains($path, '/.')) {
                continue;
            }

            foreach ($ownDirs as $ownDir) {
                if (str_starts_with($path, $ownDir)) {
                    continue 2;
                }
            }

            if (str_contains((string) $file->getContents(), $needle)) {
                $hits[] = str_replace($this->basePath().'/', '', $path);
            }
        }

        if ($hits !== []) {
            $warnings[] = 'Cross-module tests reference this module: '.implode(', ', array_slice($hits, 0, 10)).(count($hits) > 10 ? ' ('.count($hits).' total)' : '');
        }
    }

    /**
     * Locate the vendor module migration directory for a restored package.
     */
    private function migrationPathFor(string $name): ?string
    {
        $modulePath = $this->vendorModuleInfo($name)['dir'] ?? null;

        if ($modulePath === null) {
            return null;
        }

        $migrationPath = $modulePath.'/database/migrations';

        return is_dir($migrationPath) ? $migrationPath : null;
    }

    /**
     * Run migrations for a restored module so its tables come back empty.
     */
    private function runModuleMigrations(string $name): void
    {
        $path = $this->migrationPathFor($name);

        if ($path === null) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => $path,
            '--realpath' => true,
            '--force' => true,
        ]);
    }
}
