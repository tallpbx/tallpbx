<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Module;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
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
     * Register permissions for a module.
     *
     * Accepts either an indexed array of permission name strings (no descriptions)
     * or an associative array mapping permission names to their descriptions.
     *
     * @param  string  $module  The module name (e.g. 'extensions')
     * @param  array<int, string>|array<string, string|null>  $permissions  Permission names or name=>description pairs
     */
    public function register(string $module, array $permissions): void
    {
        $names = [];
        foreach ($permissions as $key => $value) {
            if (is_string($key)) {
                // Associative: name => description
                $names[] = $key;
                $this->descriptions[$key] = $value;
            } else {
                // Indexed: just a name string
                $names[] = $value;
            }
        }

        $this->permissions[$module] = array_values(array_unique([
            ...($this->permissions[$module] ?? []),
            ...$names,
        ]));
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
        if ($this->synced) {
            return Permission::query()->pluck('name')->all();
        }

        $result = [];

        foreach ($this->permissions as $perms) {
            array_push($result, ...$perms);
        }

        return $result;
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
