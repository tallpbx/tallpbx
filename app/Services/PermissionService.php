<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Module;
use App\Models\Permission;
use App\Support\PermissionSyncPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Service for module permission registration and database synchronization.
 *
 * Modules declare their permissions at boot time. The service
 * aggregates them for use by the permission management UI
 * and the MenuService for permission-gated menu visibility.
 *
 * The syncToDatabase() method persists all registered permissions
 * and cleans up stale entries.
 */
class PermissionService
{
    /**
     * All registered permission names, keyed by module.
     *
     * @var array<string, list<string>>
     */
    private array $permissions = [];

    /**
     * Descriptions for registered permissions, keyed by permission name.
     *
     * @var array<string, string|null>
     */
    private array $descriptions = [];

    /**
     * Whether the database has been synced.
     */
    private bool $synced = false;

    /**
     * In-memory memoization cache of all permissions.
     *
     * @var list<string>|null
     */
    private ?array $memoizedAll = null;

    /**
     * Register permissions for a module.
     *
     * Accepts either an indexed array of permission name strings (no descriptions)
     * or an associative array mapping permission names to their descriptions.
     *
     * Custom modules may not claim permissions already registered by another
     * module — the claim is rejected with a logged warning so a community
     * extension cannot hijack core RBAC entries (plan §1.7.5).
     *
     * @param  string  $module  The module name (e.g. 'extensions')
     * @param  array<int, string>|array<string, string|null>  $permissions  Permission names or name=>description pairs
     */
    public function register(string $module, array $permissions): void
    {
        $source = $this->moduleSource($module);
        $names = [];

        foreach ($permissions as $key => $value) {
            $name = is_string($key) ? $key : $value;

            // Ownership guard: reject a custom module's claim on a permission
            // another module already registered.
            if ($source === 'custom' && ($owner = $this->ownerOf($name)) !== null && $owner !== $module) {
                Log::warning("Custom module [{$module}] attempted to claim permission [{$name}] already owned by module [{$owner}]. The claim was rejected.");

                continue;
            }

            $names[] = $name;

            if (is_string($key)) {
                // Associative: name => description
                $this->descriptions[$name] = $value;
            }
        }

        $this->memoizedAll = null;
        $this->permissions[$module] = array_values(array_unique([
            ...($this->permissions[$module] ?? []),
            ...$names,
        ]));
    }

    /**
     * Find the module that already registered a permission, if any.
     *
     * Consults the in-memory registrations first; once synced, also checks
     * the database so permissions owned by modules whose providers did not
     * boot this request (for example disabled modules) stay protected.
     */
    private function ownerOf(string $name): ?string
    {
        foreach ($this->permissions as $module => $names) {
            if (in_array($name, $names, true)) {
                return $module;
            }
        }

        if (! $this->synced) {
            return null;
        }

        $owner = Permission::query()->where('name', $name)->value('module');

        return is_string($owner) ? $owner : null;
    }

    /**
     * Resolve a module's declared source (core, custom, or vendor).
     *
     * Defaults to 'core' whenever the module is not in the manifest, so only
     * explicitly custom modules are subject to the ownership guard.
     */
    private function moduleSource(string $module): string
    {
        try {
            if (! app()->bound('modules.manifest')) {
                return 'core';
            }

            $manifests = app('modules.manifest');

            return is_array($manifests) ? ($manifests[$module]['source'] ?? 'core') : 'core';
        } catch (\Throwable) {
            return 'core';
        }
    }

    /**
     * Clear all in-memory registrations and reset synced state.
     *
     * Used in tests to reset state between test cases.
     */
    public function reset(): void
    {
        $this->permissions = [];
        $this->descriptions = [];
        $this->synced = false;
        $this->memoizedAll = null;
    }

    /**
     * Clear the in-memory memoization cache of all permissions.
     */
    public function clearMemoization(): void
    {
        $this->memoizedAll = null;
    }

    /**
     * Check whether permissions have been synced to the database.
     */
    public function isSynced(): bool
    {
        return $this->synced;
    }

    /**
     * Get all registered permission names, flat.
     *
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->memoizedAll !== null) {
            return $this->memoizedAll;
        }

        if ($this->synced) {
            $result = Permission::query()->pluck('name')->all();
        } else {
            $result = [];

            foreach ($this->permissions as $perms) {
                array_push($result, ...$perms);
            }
        }

        return $this->memoizedAll = $result;
    }

    /**
     * Get all registered permissions, grouped by module, with descriptions.
     *
     * Each permission is returned as an array with 'name' and 'description' keys.
     *
     * @return array<string, list<array{name: string, description: string|null}>>
     */
    public function grouped(bool $includeDisabled = false): array
    {
        if ($this->synced) {
            return Permission::query()
                ->select('module', 'name', 'description')
                ->when(! $includeDisabled, fn ($query) => $query->whereNotIn('module', $this->disabledModules()))
                ->orderBy('name')
                ->get()
                ->groupBy('module')
                ->map(fn ($items) => $items->map(fn ($perm) => [
                    'name' => $perm->name,
                    'description' => $perm->description,
                ])->all())
                ->all();
        }

        $result = [];
        foreach ($this->permissions as $module => $names) {
            $result[$module] = array_map(fn (string $name) => [
                'name' => $name,
                'description' => $this->descriptions[$name] ?? null,
            ], $names);
        }

        return $result;
    }

    /**
     * Synchronize registered permissions to the database.
     *
     * Upserts all currently registered permissions (including descriptions)
     * and removes any database entries not present in the current registration.
     * After calling this method, all() and grouped() read from the database.
     */
    public function syncToDatabase(): void
    {
        DB::transaction(function () {
            $allNames = $this->registeredPermissionNames();

            // Collect all permission records to upsert
            $records = [];
            foreach ($this->permissions as $module => $perms) {
                foreach ($perms as $name) {
                    $records[] = [
                        'name' => $name,
                        'module' => $module,
                        'description' => $this->descriptions[$name] ?? null,
                    ];
                }
            }

            // Upsert current permissions
            Permission::upsert(
                $records,
                uniqueBy: ['name'],
                update: ['module', 'description'],
            );

            // Remove stale active-module permissions, but keep disabled module
            // permissions so existing group assignments survive disablement.
            Permission::whereNotIn('name', $allNames)
                ->whereNotIn('module', $this->disabledModules())
                ->delete();
        });

        $this->synced = true;
        $this->memoizedAll = null;
    }

    /**
     * Generate a deterministic sha1 hash of all declared module permissions and descriptions.
     *
     * Computed strictly from in-memory module registrations and canonicalized by
     * permission name so the hash is 100% deterministic and unaffected by module boot
     * order or whether permissions have been synchronized to the database.
     */
    public function registryHash(): string
    {
        $payload = [];

        foreach ($this->permissions as $module => $names) {
            foreach ($names as $name) {
                $payload[$name] = [
                    'module' => $module,
                    'description' => $this->descriptions[$name] ?? null,
                ];
            }
        }

        ksort($payload);

        return sha1((string) json_encode($payload));
    }

    /**
     * Synchronize permissions to database only if stale or un-synced.
     *
     * Uses PermissionSyncPolicy to check whether synchronization is required.
     * Updates the cached hash upon successful synchronization.
     */
    public function syncIfStale(?PermissionSyncPolicy $policy = null): bool
    {
        $policy ??= new PermissionSyncPolicy;
        $currentHash = $this->registryHash();
        $cachedHash = Cache::get('permissions.sync.hash');

        $request = app()->bound('request') ? request() : null;
        $requestPath = $request?->path();
        $runningInConsole = app()->runningInConsole();

        if (! $policy->shouldSync($requestPath, $runningInConsole, is_string($cachedHash) ? $cachedHash : null, $currentHash)) {
            return false;
        }

        if (! Schema::hasTable('permissions')) {
            return false;
        }

        $this->syncToDatabase();
        Cache::forever('permissions.sync.hash', $currentHash);

        return true;
    }

    /**
     * Get permission names from current in-memory registrations.
     *
     * This remains the source of truth during database rebuilds such as
     * migrate:fresh --seed, even if a previous console boot already marked
     * the service as synced against the old database.
     *
     * @return list<string>
     */
    private function registeredPermissionNames(): array
    {
        $result = [];

        foreach ($this->permissions as $perms) {
            array_push($result, ...$perms);
        }

        return $result;
    }

    /**
     * Get disabled module names, safely defaulting to none during fresh setup.
     *
     * @return list<string>
     */
    private function disabledModules(): array
    {
        try {
            if (! Schema::hasTable('modules')) {
                return [];
            }

            return Module::query()
                ->where('enabled', false)
                ->pluck('name')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
