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
 * Manages the destructive module lifecycle: complete uninstall and restore.
 *
 * An uninstall drops the module's data (through its tagged uninstall
 * handler when one exists), deletes its files and Composer entries, and
 * keeps a minimal registry marker so the module can be restored later.
 * A restore brings the module back from its origin — the git repository
 * for first-party modules, or Composer for vendor packages — re-runs its
 * migrations, and re-seeds its permissions. Database data is intentionally
 * not restored.
 */
class ModuleLifecycleService
{
    /**
     * Create the lifecycle service.
     *
     * The base path and process runners are injectable so tests can run the
     * service against a hermetic sandbox instead of the real installation.
     *
     * @param  Closure(array<int, string>): bool|null  $composerRunner  test seam that performs Composer operations
     * @param  Closure(array<int, string>): bool|null  $gitRunner  test seam that performs git operations
     */
    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
        private readonly ?string $basePath = null,
        private readonly ?Closure $composerRunner = null,
        private readonly ?Closure $gitRunner = null,
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

        // Local app-modules directories take precedence over vendor packages.
        $moduleDir = $this->moduleDir($name);
        $vendor = $moduleDir === null ? $this->vendorModuleInfo($name) : null;

        if ($moduleDir === null && $vendor === null) {
            return $this->refusal("Module [{$name}] is neither a local app-modules directory nor an installed vendor package.");
        }

        $manifest = $this->manifestFor($moduleDir ?? $vendor['dir']);

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

        $isVendor = $moduleDir === null;

        $dependents = $this->installedDependents($name);

        if ($dependents !== []) {
            return $this->refusal('Modules ['.implode(', ', $dependents).'] require this module; uninstall them first.');
        }

        $warnings = [];
        $this->appendCrossTestWarnings($name, $warnings);

        if (! $uninstallAvailable) {
            $warnings[] = "Module [{$name}] has no uninstall handler; its database tables (if any) will be left intact.";
        }

        return [
            'can_uninstall' => true,
            'reason' => null,
            'module_kind' => $isVendor ? 'vendor' : 'local',
            'module_dir' => $moduleDir,
            'vendor_package' => $isVendor ? $vendor['package'] : null,
            'composer_package' => $isVendor ? $vendor['package'] : "tallpbx/module-{$name}",
            'repository_entry' => ! $isVendor && $this->hasRepositoryEntry($name),
            'registry_row' => $registryRow !== null,
            'permission_count' => Permission::where('module', $name)->count(),
            'uninstall_items' => $uninstallItems,
            'uninstall_available' => $uninstallAvailable,
            'restore_hint' => "Restore later with: php artisan module:restore {$name} (database data is not restored)",
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

        $isVendor = $preview['module_kind'] === 'vendor';
        $moduleDirDeleted = false;

        if (! $isVendor) {
            // Only local modules own files in the installation tree. Vendor
            // packages belong to Composer, which deletes them itself.
            $moduleDirDeleted = $this->files->deleteDirectory((string) $preview['module_dir']);
        }

        $composerRemoved = $this->runComposer(['remove', $preview['composer_package'], '--no-interaction']);

        if (! $composerRemoved) {
            $preview['warnings'][] = "Composer could not remove [{$preview['composer_package']}]; run composer update manually to finish.";
        }

        $repositoryEntryRemoved = false;
        $requireEntryRemoved = false;

        if (! $isVendor) {
            // Composer remove handles the lockfile; strip the JSON entries
            // ourselves so the state is complete even if Composer failed.
            $repositoryEntryRemoved = $this->stripRepositoryEntry($name);
            $requireEntryRemoved = $this->stripRequireEntry($preview['composer_package']);
        }

        // Keep a minimal registry marker so restore knows what to bring back.
        $this->recordUninstallMarker($name, $preview);

        Artisan::call('optimize:clear');

        Log::notice('Module uninstalled.', ['module' => $name]);

        return [
            'module_kind' => $preview['module_kind'],
            'module_dir_deleted' => $moduleDirDeleted,
            'composer_package_removed' => $composerRemoved,
            'repository_entry_removed' => $repositoryEntryRemoved,
            'require_entry_removed' => $requireEntryRemoved,
            'permissions_deleted' => $permissionsDeleted,
            'uninstall_ran' => $uninstallRan,
            'restore_hint' => $preview['restore_hint'],
            'warnings' => $preview['warnings'],
        ];
    }

    // ─── Restore ────────────────────────────────────────────────────────

    /**
     * Return a human-readable restore plan for a previously uninstalled module.
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
        $isLocal = $this->isLocalModule($name);

        if (! $isLocal && ($registryRow?->composer_package === null || $registryRow?->composer_package === '')) {
            return array_merge($empty, [
                'reason' => "Module [{$name}] is not tracked in this repository and has no recorded Composer package to restore from.",
                'registry_row' => $registryRow !== null,
            ]);
        }

        return [
            'can_restore' => true,
            'reason' => null,
            'source' => $isLocal ? 'git' : 'composer',
            'module_dir' => $this->basePath()."/app-modules/{$name}",
            'composer_package' => $isLocal ? "tallpbx/module-{$name}" : $registryRow->composer_package,
            'registry_row' => $registryRow !== null,
            'items' => $isLocal
                ? [
                    "Restore the module directory (code, migrations, factories, and tests) from git: app-modules/{$name}",
                    'Re-add the Composer path-repository and require entries',
                ]
                : [
                    "Reinstall the Composer package: {$registryRow->composer_package}",
                ],
            'data_notice' => 'The module returns with empty tables; uninstalled data is not restored.',
        ];
    }

    /**
     * Restore a previously uninstalled module from its origin.
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

        $restored = false;

        if ($preview['source'] === 'git') {
            // The module directory contains its code, migrations, factories,
            // views, AND tests — one restore brings everything back.
            $restored = $this->runGit(['restore', '--source=HEAD', '--', "app-modules/{$name}"]);

            if (! $restored) {
                throw ValidationException::withMessages([
                    'module' => "Could not restore module files from git. Check that [app-modules/{$name}] is committed and the installation is a git checkout.",
                ]);
            }

            $this->addRepositoryEntry($name);
            $this->addRequireEntry($preview['composer_package']);
        } else {
            $restored = $this->runComposer(['require', $preview['composer_package'], '--no-interaction']);

            if (! $restored) {
                throw ValidationException::withMessages([
                    'module' => "Composer could not reinstall [{$preview['composer_package']}].",
                ]);
            }
        }

        // Refresh the registry row from the restored manifest and re-enable it.
        $this->refreshRegistryRow($name);

        // Recreate the module's tables (empty) by re-running its migrations.
        $this->runModuleMigrations($name);

        // Re-seed permission rows for every installed module (idempotent).
        Artisan::call('db:seed', ['--class' => AdminSeeder::class, '--force' => true]);

        Artisan::call('optimize:clear');

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
            'composer_package' => '',
            'repository_entry' => false,
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
     * Resolve the local app-modules directory for a module name.
     */
    private function moduleDir(string $name): ?string
    {
        $dir = $this->basePath()."/app-modules/{$name}";

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
     * Whether the module is a first-party module tracked in this git checkout.
     */
    private function isLocalModule(string $name): bool
    {
        return $this->runGit(['cat-file', '-e', "HEAD:app-modules/{$name}/module.json"]);
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
     * Run a git command, or the injected test seam.
     *
     * @param  array<int, string>  $arguments
     */
    private function runGit(array $arguments): bool
    {
        if ($this->gitRunner !== null) {
            return ($this->gitRunner)($arguments);
        }

        $process = new Process(array_merge(['git'], $arguments), $this->basePath());
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Read the root composer.json into an associative array.
     *
     * @return array<string, mixed>
     */
    private function readComposer(): array
    {
        $contents = @file_get_contents($this->basePath().'/composer.json');

        if ($contents === false) {
            return [];
        }

        $composer = json_decode($contents, true);

        return is_array($composer) ? $composer : [];
    }

    /**
     * Persist the root composer.json after a change.
     *
     * @param  array<string, mixed>  $composer
     */
    private function writeComposer(array $composer): void
    {
        $this->files->put(
            $this->basePath().'/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * Whether the root composer.json declares this module as a path repository.
     */
    private function hasRepositoryEntry(string $name): bool
    {
        $composer = $this->readComposer();

        return collect($composer['repositories'] ?? [])
            ->contains(fn (array $repo): bool => ($repo['url'] ?? '') === "app-modules/{$name}");
    }

    /**
     * Remove the module's path-repository entry from the root composer.json.
     */
    private function stripRepositoryEntry(string $name): bool
    {
        $composer = $this->readComposer();

        if ($composer === []) {
            return false;
        }

        $composer['repositories'] = collect($composer['repositories'] ?? [])
            ->reject(fn (array $repo): bool => ($repo['url'] ?? '') === "app-modules/{$name}")
            ->values()
            ->all();

        $this->writeComposer($composer);

        return true;
    }

    /**
     * Remove the module's require entry from the root composer.json.
     */
    private function stripRequireEntry(string $package): bool
    {
        $composer = $this->readComposer();

        if ($composer === []) {
            return false;
        }

        if (! isset($composer['require'][$package])) {
            return true;
        }

        unset($composer['require'][$package]);
        $this->writeComposer($composer);

        return true;
    }

    /**
     * Re-add the module's path-repository entry to the root composer.json.
     */
    private function addRepositoryEntry(string $name): bool
    {
        $composer = $this->readComposer();

        if ($composer === []) {
            return false;
        }

        $repositories = $composer['repositories'] ?? [];

        foreach ($repositories as $repo) {
            if (is_array($repo) && ($repo['url'] ?? '') === "app-modules/{$name}") {
                return true; // already present
            }
        }

        $repositories[] = ['type' => 'path', 'url' => "app-modules/{$name}"];
        $composer['repositories'] = $repositories;

        $this->writeComposer($composer);

        return true;
    }

    /**
     * Re-add the module's Composer require entry to the root composer.json.
     */
    private function addRequireEntry(string $package): bool
    {
        $composer = $this->readComposer();

        if ($composer === []) {
            return false;
        }

        if (isset($composer['require'][$package])) {
            return true; // already present
        }

        $composer['require'][$package] = '*';
        $this->writeComposer($composer);

        return true;
    }

    /**
     * Keep or create a registry row marking the module as uninstalled so the
     * restore command can determine where the module comes from.
     *
     * @param  array<string, mixed>  $preview
     */
    private function recordUninstallMarker(string $name, array $preview): void
    {
        $attributes = [
            'enabled' => false,
            'status' => Module::StatusUninstalled,
            'composer_package' => $preview['composer_package'],
        ];

        $registryRow = Module::where('name', $name)->first();

        if ($registryRow !== null) {
            $registryRow->update($attributes);

            return;
        }

        // A module that was never synced still needs a marker so restore can
        // recover its origin (especially vendor package names).
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
        $dir = $this->moduleDir($name);

        if ($dir === null) {
            $dir = $this->vendorModuleInfo($name)['dir'] ?? null;
        }

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
            glob($this->basePath().'/vendor/*/*/module.json') ?: [],
        ) as $path) {
            $manifest = $this->manifestFor(dirname($path));

            if ($manifest === null || ($manifest['name'] ?? null) === $name) {
                continue;
            }

            $deps = $manifest['requirements']['modules'] ?? [];

            if (in_array($name, $deps, true)) {
                $dependents[] = $manifest['name'];
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
            $this->basePath()."/app-modules/{$name}",
        ];
        $hits = [];

        // Cross-module references can live in the central tests tree OR inside
        // another module's own tests — scan both.
        $scanRoots = array_filter([
            $this->basePath().'/tests',
            $this->basePath().'/app-modules',
        ], 'is_dir');

        foreach ($this->files->allFiles($scanRoots) as $file) {
            $path = $file->getPathname();

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
            $warnings[] = 'Cross-module tests reference this module and will be skipped until it is restored: '.implode(', ', array_slice($hits, 0, 10)).(count($hits) > 10 ? ' ('.count($hits).' total)' : '');
        }
    }

    /**
     * Locate the module migration directory from local or vendor modules.
     */
    private function migrationPathFor(string $name): ?string
    {
        $modulePath = $this->moduleDir($name);

        if ($modulePath === null) {
            $modulePath = $this->vendorModuleInfo($name)['dir'] ?? null;
        }

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
