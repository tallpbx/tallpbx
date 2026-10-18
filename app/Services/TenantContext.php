<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves and manages the active tenant context using a three-tier resolution
 * strategy: session → authenticated user's default → single-tenant fallback.
 *
 * This service provides decoupled query resolution (resolve/current) with zero
 * mutation side effects, paired with explicit state mutation methods (apply/switch)
 * that configure TenantManager and session storage.
 *
 * Boundary Discipline:
 *   - resolve() and current() are pure queries with zero side effects.
 *   - apply() explicitly configures TenantManager and session persistence.
 *   - The single-tenant fallback provides convenience in single-tenant deployments,
 *     but security middleware (ScopeTenant, EnforcePanelLivewireActionPermissions)
 *     must explicitly verify tenant membership (isInTenant) before calling apply().
 */
class TenantContext
{
    /**
     * Create a new TenantContext service instance.
     */
    public function __construct(
        private readonly TenantManager $manager,
    ) {}

    /**
     * Get the active tenant context without mutation side effects.
     *
     * Returns the currently applied Tenant if TenantManager is configured;
     * otherwise falls back to pure resolution without mutating state.
     */
    public function current(): ?Tenant
    {
        if ($this->manager->hasTenant()) {
            $tenantId = $this->manager->getTenantId();

            if ($tenantId !== null) {
                $tenant = Tenant::query()
                    ->whereKey($tenantId)
                    ->where('enabled', true)
                    ->first();

                if ($tenant !== null) {
                    return $tenant;
                }
            }
        }

        return $this->resolve();
    }

    /**
     * Pure query resolving candidate tenant context with zero mutation side effects.
     *
     * When a User is provided, resolves the user's primary or attached tenant.
     * When no User is provided, evaluates the three-tier resolution order:
     *   1. Session-stored tenant (selected_tenant_id)
     *   2. Authenticated user's default tenant (User model only)
     *   3. Single-tenant fallback (if exactly one enabled tenant exists)
     *
     * Does NOT mutate TenantManager or session.
     */
    public function resolve(?User $user = null): ?Tenant
    {
        if ($user !== null) {
            return $this->resolveForUser($user);
        }

        // 1. Session-stored tenant
        if (session()->has('selected_tenant_id')) {
            $tenant = Tenant::query()
                ->whereKey((string) session()->get('selected_tenant_id'))
                ->where('enabled', true)
                ->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        // 2. Authenticated user's default tenant (User model only)
        if (Auth::guard('web')->check()) {
            $authUser = Auth::guard('web')->user();

            // Only User models have tenant relationships. An Admin
            // authenticated via the web guard should not trigger tenant
            // resolution — return null so downstream middleware can
            // redirect them to the admin login.
            if ($authUser instanceof User) {
                $tenant = $this->resolveForUser($authUser);

                if ($tenant !== null) {
                    return $tenant;
                }
            }
        }

        // 3. Single-tenant fallback (only if exactly one enabled tenant exists)
        if (Tenant::query()->where('enabled', true)->count() === 1) {
            return Tenant::query()->where('enabled', true)->first();
        }

        return null;
    }

    /**
     * Explicitly apply the tenant context to TenantManager and session.
     *
     * When a Tenant is provided, sets the active tenant ID on TenantManager
     * and persists it to the session. When null is provided, clears both.
     */
    public function apply(?Tenant $tenant): void
    {
        if ($tenant !== null) {
            $this->manager->setTenantId((string) $tenant->id);
            session()->put('selected_tenant_id', (string) $tenant->id);
        } else {
            $this->manager->clear();
            session()->forget('selected_tenant_id');
        }
    }

    /**
     * Switch the current tenant context and persist to session.
     *
     * Alias for apply($tenant).
     */
    public function switch(Tenant $tenant): void
    {
        $this->apply($tenant);
    }

    /**
     * Resolve the default tenant for a given user.
     *
     * Resolution order:
     *   1. User's primary tenant (where tenant_user.primary = true)
     *   2. User's first attached tenant
     *   3. Single-tenant fallback (if exactly one enabled tenant exists)
     */
    protected function resolveForUser(User $user): ?Tenant
    {
        // 1. Primary tenant
        $primary = $user->tenants()
            ->wherePivot('primary', true)
            ->where('tenants.enabled', true)
            ->first();

        if ($primary !== null) {
            return $primary;
        }

        // 2. First attached tenant
        $first = $user->tenants()
            ->where('tenants.enabled', true)
            ->first();

        if ($first !== null) {
            return $first;
        }

        // 3. Single-tenant fallback (only if exactly one enabled tenant exists)
        if (Tenant::query()->where('enabled', true)->count() === 1) {
            return Tenant::query()->where('enabled', true)->first();
        }

        return null;
    }
}
