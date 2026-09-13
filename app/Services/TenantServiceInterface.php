<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service for managing customer tenants, the shared Default tenant,
 * and tenant user memberships.
 */
interface TenantServiceInterface
{
    /**
     * Create a new tenant and provision its PBX defaults.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tenant;

    /**
     * Create or return the shared-resource Default tenant.
     */
    public function defaultTenant(): Tenant;

    /**
     * Update an existing tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, array $data): Tenant;

    /**
     * Delete a tenant and all its relationships.
     */
    public function delete(Tenant $tenant): void;

    /**
     * Add a user to a tenant with a given role.
     */
    public function addUser(Tenant $tenant, User $user, string $role = 'member'): void;

    /**
     * Remove a user from a tenant.
     */
    public function removeUser(Tenant $tenant, User $user): void;

    /**
     * Get all tenants.
     *
     * @return Collection<int, Tenant>
     */
    public function all(): Collection;

    /**
     * Find a tenant by its slug.
     */
    public function findBySlug(string $slug): ?Tenant;

    /**
     * Enable or disable a tenant.
     */
    public function setEnabled(Tenant $tenant, bool $enabled): Tenant;
}
