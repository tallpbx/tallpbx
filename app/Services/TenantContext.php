<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the current tenant context using a three-tier resolution
 * strategy: session → authenticated user's default → single-tenant fallback.
 *
 * This service wraps TenantManager with automatic resolution logic
 * and session persistence, providing a unified API for tenant context
 * management throughout the application.
 *
 * Resolution order for current():
 *   1. Session-stored tenant (selected_tenant_id)
 *   2. Authenticated user's primary/default tenant
 *   3. Single-tenant fallback (if exactly one tenant exists)
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
     * Resolve the current tenant using the three-tier strategy.
     *
     * Returns the resolved Tenant or null if no tenant context
     * can be determined.
     */
    public function current(): ?Tenant
    {
        // 1. Session-stored tenant
        if (session()->has('selected_tenant_id')) {
            $tenant = Tenant::query()
                ->whereKey((string) session()->get('selected_tenant_id'))
                ->where('enabled', true)
                ->first();

            if ($tenant !== null) {
                $this->manager->setTenantId((string) $tenant->id);

                return $tenant;
            }
        }

        // 2. Authenticated user's default tenant (User model only)
        if (Auth::guard('web')->check()) {
            $user = Auth::guard('web')->user();

            // Only User models have tenant relationships. An Admin
            // authenticated via the web guard should not trigger tenant
            // resolution — return null so downstream middleware can
            // redirect them to the admin login.
            if ($user instanceof User) {
                $tenant = $this->resolve($user);

                if ($tenant !== null) {
                    $this->manager->setTenantId((string) $tenant->id);
                    session()->put('selected_tenant_id', (string) $tenant->id);

                    return $tenant;
                }
            }
        }

        // 3. Single-tenant fallback
        if (Tenant::where('enabled', true)->count() === 1) {
            $tenant = Tenant::where('enabled', true)->first();

            if ($tenant !== null) {
                $this->manager->setTenantId((string) $tenant->id);

                return $tenant;
            }
        }

        return null;
    }

    /**
     * Switch the current tenant context and persist to session.
     */
    public function switch(Tenant $tenant): void
    {
        $this->manager->setTenantId((string) $tenant->id);
        session()->put('selected_tenant_id', (string) $tenant->id);
    }

    /**
     * Resolve the default tenant for a given user.
     *
     * Resolution order:
     *   1. User's primary tenant (where tenant_user.primary = true)
     *   2. User's first attached tenant
     *   3. Single-tenant fallback
     */
    public function resolve(User $user): ?Tenant
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

        // 3. Single-tenant fallback
        if (Tenant::where('enabled', true)->count() === 1) {
            return Tenant::where('enabled', true)->first();
        }

        return null;
    }
}
