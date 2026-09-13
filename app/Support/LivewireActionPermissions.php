<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\PermissionService;
use Illuminate\Support\Str;

/**
 * Resolves the permission abilities required to invoke a Livewire action
 * on a panel module component.
 *
 * The Livewire update endpoint serves every component in one shared route,
 * so page-route permission middleware never sees mutation actions. This
 * resolver supplies the convention-based ability requirements that the
 * EnforcePanelLivewireActionPermissions middleware checks instead:
 *
 *   - "save" on an Edit component  → the module edit or create permission
 *   - "delete*" actions            → the module delete permission
 *   - anything else (property sync, confirmation dialogs, pagination)
 *                                  → the module view permission
 *
 * Abilities that a module never declared fall back to view, then to no
 * requirement at all, so a module without matching permissions keeps
 * working exactly as before instead of being locked out.
 *
 * A component may override the convention by defining a static
 * livewireActionAbilities() method that maps action names to ability
 * groups, e.g. ['sendBroadcast' => [['call-broadcast.create']]].
 * Every returned group must be satisfied; within a group any single
 * ability passing is enough.
 */
class LivewireActionPermissions
{
    /** Cached list of registered permission names for the request. */
    private ?array $registered = null;

    /**
     * Create the resolver.
     *
     * @param  PermissionService  $permissions  Source of registered permission names
     */
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * Resolve the ability groups required for one action on a component.
     *
     * @param  string  $componentClass  Fully qualified Livewire component class
     * @param  string  $action  Invoked method name; empty string means a property-only update
     * @return array<int, array<int, string>> Ability groups (all groups required, any ability per group)
     */
    public function abilitiesFor(string $componentClass, string $action): array
    {
        // Component-level overrides always win over the naming convention.
        if (method_exists($componentClass, 'livewireActionAbilities')) {
            $overrides = $componentClass::livewireActionAbilities();

            if (isset($overrides[$action])) {
                return $overrides[$action];
            }
        }

        $module = $this->moduleSlug($componentClass);

        if ($module === null) {
            return [];
        }

        // Form submissions on edit forms create or update records, so the
        // actor needs whichever of the two write permissions the module has.
        if ($action === 'save' && str_ends_with($componentClass, 'Edit')) {
            $candidates = array_values(array_filter(
                [$module.'.edit', $module.'.create'],
                fn (string $ability): bool => $this->isRegistered($ability),
            ));

            if ($candidates !== []) {
                return [$candidates];
            }

            return $this->viewGroup($module);
        }

        // Deletion actions require the module delete permission when it exists.
        if (str_starts_with($action, 'delete')) {
            if ($this->isRegistered($module.'.delete')) {
                return [[$module.'.delete']];
            }

            return $this->viewGroup($module);
        }

        // Property sync, confirmation dialogs, pagination, and any other
        // interaction need at least the view permission.
        return $this->viewGroup($module);
    }

    /**
     * Build the fallback ability group that only requires module viewing.
     *
     * Returns an empty list when the module declares no view permission so
     * modules without matching permissions are left unchanged.
     *
     * @return array<int, array<int, string>>
     */
    private function viewGroup(string $module): array
    {
        $view = $module.'.view';

        return $this->isRegistered($view) ? [[$view]] : [];
    }

    /**
     * Derive the kebab-case module name from a component class namespace.
     *
     * For example Modules\CallCenters\Livewire\QueueEdit becomes
     * "call-centers", matching how module permissions are named.
     */
    private function moduleSlug(string $componentClass): ?string
    {
        $segments = explode('\\', $componentClass);

        if (($segments[0] ?? '') !== 'Modules' || ! isset($segments[1])) {
            return null;
        }

        return Str::kebab($segments[1]);
    }

    /**
     * Check whether a permission name is registered by any module provider.
     */
    private function isRegistered(string $ability): bool
    {
        if ($this->registered === null) {
            $this->registered = $this->permissions->all();
        }

        return in_array($ability, $this->registered, true);
    }
}
