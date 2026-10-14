<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Module;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Synchronizes module manifests from the filesystem into the modules table.
 *
 * Scans module.json files in app-modules/, custom-modules/, and vendor/
 * directories and upserts each module's metadata — including its discovery
 * source and canonical UI category — while preserving user-set
 * enabled/disabled state. Finishes by regenerating the module manifest
 * cache so bootstrap/cache/modules.php never goes stale after a sync.
 */
class ModuleSyncCommand extends Command
{
    protected $signature = 'module:sync
        {--only-local : Only sync modules from the local app-modules/ directory, skip vendor}';

    protected $description = 'Synchronize module manifests from the filesystem into the database';

    /**
     * Execute the console command.
     *
     * Scans module.json files in app-modules/ and vendor/ directories,
     * upserts each module's metadata into the modules table while
     * preserving user-set enabled/disabled state.
     */
    public function handle(): int
    {
        $this->components->info('Scanning module manifests...');

        $manifests = [];

        // Scan first-party core modules.
        $coreCount = $this->scanDirectory(base_path('app-modules/*/module.json'), $manifests, 'core');
        $this->components->twoColumnDetail('Core modules found', (string) $coreCount);

        // Scan custom modules.
        $customCount = $this->scanDirectory(base_path('custom-modules/*/module.json'), $manifests, 'custom');
        $this->components->twoColumnDetail('Custom modules found', (string) $customCount);

        // Optionally scan vendor modules
        if (! $this->option('only-local')) {
            $vendorCount = $this->scanDirectory(base_path('vendor/*/*/module.json'), $manifests, 'vendor');
            $this->components->twoColumnDetail('Vendor modules found', (string) $vendorCount);
        }

        if (empty($manifests)) {
            $this->components->warn('No module manifests found.');

            return self::SUCCESS;
        }

        $synced = 0;
        $skipped = 0;

        foreach ($manifests as $name => $manifest) {
            $result = $this->syncModule($name, $manifest);

            if ($result) {
                $synced++;
            } else {
                $skipped++;
            }
        }

        $this->components->twoColumnDetail('Modules synced', (string) $synced);

        if ($skipped > 0) {
            $this->components->twoColumnDetail('Skipped (existing)', (string) $skipped);
        }

        $this->components->info('Module database sync completed successfully.');

        // Reconcile the generated manifest cache in the same step: a sync that
        // left bootstrap/cache/modules.php stale would keep serving outdated
        // metadata until the next manual module:cache.
        Artisan::call('module:cache');
        $this->components->info('Module manifest cache regenerated.');

        return self::SUCCESS;
    }

    /**
     * Scan a glob pattern for module.json files and collect valid manifests.
     */
    private function scanDirectory(string $pattern, array &$manifests, string $source = 'core'): int
    {
        $count = 0;

        foreach (glob($pattern) as $path) {
            $manifest = $this->loadManifest($path);

            if ($manifest === null) {
                continue;
            }

            $manifest['source'] = $source;
            $manifest['path'] = dirname($path);

            $name = $manifest['name'];

            if (! isset($manifests[$name])) {
                $manifests[$name] = $manifest;
                $count++;
            }
        }

        return $count;
    }

    /**
     * Load and validate a single module.json file.
     */
    private function loadManifest(string $path): ?array
    {
        // A parallel installer or test may remove a module after glob() finds
        // it. Treat that vanished optional file as absent instead of failing a
        // complete module synchronization run.
        $content = @file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $manifest = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->components->warn("Invalid JSON in [{$path}].");

            return null;
        }

        $required = ['name', 'version', 'namespace', 'display_name'];

        foreach ($required as $field) {
            if (! isset($manifest[$field]) || (is_string($manifest[$field]) && trim($manifest[$field]) === '')) {
                $this->components->warn("Manifest [{$path}] missing required field: [{$field}].");

                return null;
            }
        }

        // Canonical kebab-case only, matching make:module: names must
        // round-trip through the Str::kebab namespace mapping used by
        // dependency matching, so underscore names are refused here too.
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $manifest['name'])) {
            $this->components->warn("Manifest [{$path}] has invalid module name.");

            return null;
        }

        return $manifest;
    }

    /**
     * Upsert a single module record from its manifest data.
     *
     * Preserves the user-set enabled state — only sets enabled from
     * the manifest for newly created modules.
     */
    private function syncModule(string $name, array $manifest): bool
    {
        $data = [
            'display_name' => $manifest['display_name'],
            'version' => $manifest['version'],
            'source' => $manifest['source'] ?? 'core',
            'category' => $manifest['category'] ?? null,
            'protected' => $manifest['protected'] ?? false,
            'required' => $manifest['required'] ?? false,
            'priority' => $manifest['priority'] ?? 0,
        ];

        $existing = Module::where('name', $name)->first();

        if ($existing) {
            // Preserve user-set enabled state — only update authoritative fields
            $needsUpdate = false;

            if ($existing->status === Module::StatusEnabled && ! $existing->enabled) {
                $data['status'] = Module::StatusDisabled;
            }

            foreach ($data as $field => $value) {
                if ($value !== $existing->$field) {
                    $needsUpdate = true;
                    break;
                }
            }

            if ($needsUpdate) {
                $existing->update($data);
            }

            return false; // counted as "skipped" (already existed)
        }

        // New module: use manifest defaults, enabled by default
        $data['name'] = $name;
        $data['enabled'] = true;
        $data['status'] = Module::StatusEnabled;

        Module::create($data);

        return true;
    }
}
