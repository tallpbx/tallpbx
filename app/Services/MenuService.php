<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Service that provides the menu tree for the admin and portal layouts.
 *
 * Modules register their menu items at boot time via the static
 * register() method. The service merges in-memory registrations
 * with persisted menu entries from the database, building a
 * hierarchical, permission-gated tree.
 */
class MenuService
{
    /**
     * In-memory menu item registrations.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $registrations = [];

    /**
     * Registered permissions (module => permissions[]).
     *
     * @var array<string, list<string>>
     */
    private array $permissions = [];

    /**
     * Register menu items for a module.
     *
     * Called from a module's ModuleServiceProvider::boot().
     *
     * @param  string  $module  The module name (e.g. 'admin', 'extensions')
     * @param  array<int, array<string, mixed>>  $items
     */
    public function register(string $module, array $items): void
    {
        $this->registrations[$module] = array_merge(
            $this->registrations[$module] ?? [],
            $items,
        );
    }

    /**
     * Clear all in-memory registrations and permissions.
     *
     * Used in tests to reset state between test cases.
     */
    public function reset(): void
    {
        $this->registrations = [];
        $this->permissions = [];
    }

    /**
     * Register permissions for a module.
     *
     * @param  string  $module  The module name
     * @param  list<string>  $permissions
     */
    public function registerPermissions(string $module, array $permissions): void
    {
        $this->permissions[$module] = $permissions;
    }

    /**
     * Get all registered permissions, flat.
     *
     * @return list<string>
     */
    public function allPermissions(): array
    {
        $result = [];

        foreach ($this->permissions as $perms) {
            array_push($result, ...$perms);
        }

        return $result;
    }

    /**
     * Get the flat collection of menu items for a given guard,
     * merging in-memory registrations with DB entries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFlat(?string $guard = null): array
    {
        $dbItems = Schema::hasTable('menus')
            ? Menu::query()
                ->when($guard, fn ($q) => $q->guard($guard))
                ->enabled()
                ->orderBy('order')
                ->get()
                ->keyBy('key')
            : collect();

        $merged = [];

        foreach ($this->registrations as $module => $items) {
            foreach ($items as $item) {
                $itemGuard = $item['guard'] ?? 'web';

                if ($guard !== null && $itemGuard !== $guard) {
                    continue;
                }

                $dbItem = $dbItems->get($item['key']);

                if ($dbItem) {
                    $merged[] = $dbItem->toArray();
                } else {
                    $merged[] = $item;
                }
            }
        }

        // Add any DB-only items not registered in-memory
        foreach ($dbItems as $key => $dbItem) {
            $found = false;

            foreach ($merged as $m) {
                if (($m['key'] ?? '') === $key) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $merged[] = $dbItem->toArray();
            }
        }

        usort($merged, fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $merged;
    }

    /**
     * Get the hierarchical menu tree for a given guard.
     *
     * Builds parent/child relationships based on parent_id or parent key.
     * Filters out items the current user cannot access (permission gate).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTree(?string $guard = null): array
    {
        $flat = $this->getFlat($guard);
        $tree = [];
        $children = [];

        // Separate root items from children
        foreach ($flat as $item) {
            if ($this->cannotView($item)) {
                continue;
            }

            $parentKey = $item['parent_id'] ?? $item['parent'] ?? null;

            if ($parentKey === null) {
                $tree[] = $item;
            } else {
                $children[$parentKey][] = $item;
            }
        }

        // Recursively attach children
        $this->attachChildren($tree, $children);

        return $tree;
    }

    /**
     * Check whether the current user can view a menu item.
     */
    private function cannotView(array $item): bool
    {
        $permission = $item['permission'] ?? null;

        if ($permission === null) {
            return false;
        }

        if (! Gate::has($permission)) {
            return false;
        }

        $user = Auth::user();

        if ($user === null) {
            $user = Auth::guard('admin')->user();
        }

        if ($user === null) {
            return true; // hide from guests
        }

        return ! $user->can($permission);
    }

    /**
     * Recursively attach children to their parent items.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, array<int, array<string, mixed>>>  $children
     */
    private function attachChildren(array &$items, array &$children): void
    {
        foreach ($items as &$item) {
            $key = $item['key'] ?? $item['id'] ?? null;

            if ($key !== null && isset($children[$key])) {
                $item['children'] = $children[$key];
                $this->attachChildren($item['children'], $children);
            }
        }
    }
}
