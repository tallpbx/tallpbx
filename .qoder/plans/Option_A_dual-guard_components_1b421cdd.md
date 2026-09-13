# Option A: Dual-Guard Shared Components

## Approach
Shared Livewire components detect the guard (admin vs web) at runtime and adapt layout, tenant scoping, and routing accordingly. A `route_guard()` helper resolves route name prefixes in Blade views so the same view works for both guards.

## Files Changed

### Phase 1: Infrastructure (4 files)

**1. New: `app/helpers.php`** — `route_guard()` helper
```php
function route_guard(string $name, mixed $parameters = [], bool $absolute = true): string
{
    $prefix = Auth::guard('admin')->check() ? 'admin.' : 'tenant.';
    return route($prefix . $name, $parameters, $absolute);
}
```

**2. Modify: `app/Support/BaseListComponent.php`**
- Remove `#[Layout('layouts.admin')]` static attribute
- Add `boot()` that sets layout dynamically via `$this->layout()`
- Add `isAdminGuard(): bool` helper

**3. Modify: `app/Support/BaseEditComponent.php`**
- Same layout changes as BaseListComponent
- `loadTenants()` guarded: only loads tenant list for admin
- `mount()` auto-sets `$this->tenantId` to current user's tenant for client users

**4. Modify: `app/Support/ModuleServiceProvider.php`**
- Default `routeGroups()` adds a client group:
  ```php
  ['enabled' => true, 'prefix' => 'tenant', 'middleware' => ['web', 'auth', 'tenant', 'throttle:60,1'],
   'name_prefix' => 'tenant.', 'permission_callback' => fn($a) => '', 'component_suffixes' => ['List', 'Edit']]
  ```

### Phase 2: Module Conversion (per module)

For each of the ~38 standard-naming modules:

| File | Change |
|------|--------|
| `ModuleServiceProvider.php` | Add `guard => 'web'` menu item mirroring admin entry + point at `tenant.{name}.index` |
| Livewire List component | Remove `#[Layout]`; guard `withoutGlobalScope('tenant')` calls |
| Livewire Edit component | Remove `#[Layout]`; guard `withoutGlobalScope` calls; guard `$tenantId` validation rule |
| Blade List view | Replace `route('admin.X')` with `route_guard('X')` |
| Blade Edit view | Replace `route('admin.X')` with `route_guard('X')`; hide tenant dropdown for non-admin |

### Phase 3: Route Consolidation (1 file)

**Modify: `routes/web.php`**
- Remove hardcoded `/tenant/inbound-routes` and `/tenant/outbound-routes` entries since they will be auto-registered by module providers

### Execution Order

1. Phase 1 infrastructure first (test with Bridges module)
2. Run bridges tests to confirm
3. Phase 2 for all 38 standard modules (batch script approach)
4. Phase 3 route consolidation
5. Full test suite
6. Update AGENTS.md
7. Present for commit approval