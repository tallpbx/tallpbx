<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ModuleUninstaller;
use App\Models\Module;
use App\Models\Permission;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Manages module lifecycle transitions beyond enable and disable.
 */
class ModuleLifecycleService
{
    /**
     * Create the service with access to tagged module uninstall handlers.
     */
    public function __construct(private readonly Application $app) {}

    /**
     * Build the exact phrase required to destructively uninstall a module.
     */
    public function confirmationPhrase(Module $module): string
    {
        return 'UNINSTALL '.$module->name;
    }

    /**
     * Return a human-readable uninstall preview for a module.
     *
     * @return array{can_uninstall: bool, confirmation: string, items: array<int, string>, reason: string|null}
     */
    public function previewUninstall(Module $module): array
    {
        if ($module->required || $module->protected) {
            return [
                'can_uninstall' => false,
                'confirmation' => $this->confirmationPhrase($module),
                'items' => [],
                'reason' => 'Required and protected modules cannot be uninstalled.',
            ];
        }

        $uninstaller = $this->uninstallerFor($module->name);

        if ($uninstaller === null) {
            return [
                'can_uninstall' => false,
                'confirmation' => $this->confirmationPhrase($module),
                'items' => [],
                'reason' => 'This module does not provide an uninstall handler.',
            ];
        }

        if (! $uninstaller->canUninstall($module)) {
            return [
                'can_uninstall' => false,
                'confirmation' => $this->confirmationPhrase($module),
                'items' => $uninstaller->previewUninstall($module),
                'reason' => 'This module cannot be uninstalled right now.',
            ];
        }

        return [
            'can_uninstall' => true,
            'confirmation' => $this->confirmationPhrase($module),
            'items' => $uninstaller->previewUninstall($module),
            'reason' => null,
        ];
    }

    /**
     * Destructively uninstall a module after exact confirmation.
     *
     * @throws ValidationException
     */
    public function uninstall(Module $module, string $confirmation): void
    {
        $preview = $this->previewUninstall($module);

        if (! $preview['can_uninstall']) {
            throw ValidationException::withMessages([
                'uninstallConfirmation' => $preview['reason'] ?? 'This module cannot be uninstalled.',
            ]);
        }

        if ($confirmation !== $this->confirmationPhrase($module)) {
            throw ValidationException::withMessages([
                'uninstallConfirmation' => 'The confirmation phrase did not match.',
            ]);
        }

        $uninstaller = $this->uninstallerFor($module->name);

        DB::transaction(function () use ($module, $uninstaller): void {
            $uninstaller?->uninstall($module);

            Permission::query()
                ->where('module', $module->name)
                ->delete();

            $module->update([
                'enabled' => false,
                'status' => Module::StatusUninstalled,
            ]);
        });

        Artisan::call('optimize:clear');

        Log::notice('Module uninstalled.', [
            'module' => $module->name,
        ]);
    }

    /**
     * Reinstall a module that is still discoverable from its module.json manifest.
     */
    public function reinstall(string $name): Module
    {
        $manifest = $this->manifestFor($name);

        if ($manifest === null) {
            throw ValidationException::withMessages([
                'reinstall' => "Module [{$name}] is not available on disk or through Composer.",
            ]);
        }

        $module = DB::transaction(function () use ($name, $manifest): Module {
            return Module::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $manifest['display_name'],
                    'version' => $manifest['version'],
                    'enabled' => true,
                    'status' => Module::StatusEnabled,
                    'protected' => $manifest['protected'] ?? false,
                    'required' => $manifest['required'] ?? false,
                    'priority' => $manifest['priority'] ?? 0,
                ],
            );
        });

        $this->runModuleMigrations($name);

        Artisan::call('optimize:clear');

        Log::notice('Module reinstalled.', [
            'module' => $name,
        ]);

        return $module;
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
     * Read a module manifest from the discovery registry.
     *
     * @return array<string, mixed>|null
     */
    private function manifestFor(string $name): ?array
    {
        $manifests = $this->app->make('modules.manifest');

        return $manifests[$name] ?? null;
    }

    /**
     * Run migrations for a reinstalled module so dropped tables come back.
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

    /**
     * Locate the module migration directory from local or Composer-installed modules.
     */
    private function migrationPathFor(string $name): ?string
    {
        $modulePath = $this->modulePathFor($name);

        if ($modulePath === null) {
            return null;
        }

        $migrationPath = $modulePath.'/database/migrations';

        return is_dir($migrationPath) ? $migrationPath : null;
    }

    /**
     * Locate a module directory by reading discoverable module manifests.
     */
    private function modulePathFor(string $name): ?string
    {
        foreach ([base_path('app-modules/*/module.json'), base_path('vendor/*/*/module.json')] as $pattern) {
            foreach (glob($pattern) as $path) {
                $contents = file_get_contents($path);

                if ($contents === false) {
                    continue;
                }

                $manifest = json_decode($contents, true);

                if (is_array($manifest) && ($manifest['name'] ?? null) === $name) {
                    return dirname($path);
                }
            }
        }

        return null;
    }
}
