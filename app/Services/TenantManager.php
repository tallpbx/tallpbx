<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Manages the current tenant context within the session.
 *
 * Provides simple getter/setter access to the active tenant ID,
 * which is used by the BelongsToTenant trait to scope Eloquent queries
 * and automatically assign new models to the current tenant.
 */
class TenantManager
{
    protected ?string $tenantId = null;

    /**
     * Set the active tenant ID.
     */
    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Get the active tenant ID.
     */
    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Determine whether a tenant is currently active.
     */
    public function hasTenant(): bool
    {
        return ! is_null($this->tenantId);
    }

    /**
     * Clear the active tenant context.
     */
    public function clear(): void
    {
        $this->tenantId = null;
    }
}
