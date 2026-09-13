<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service for managing users, their tenant memberships, and group assignments.
 */
interface UserServiceInterface
{
    /**
     * Create a new user.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User;

    /**
     * Update an existing user.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User;

    /**
     * Create or update a user and replace their assignments atomically.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $tenantIds
     * @param  array<int, string>  $groupIds
     */
    public function saveWithAssignments(?User $user, array $data, array $tenantIds, array $groupIds): User;

    /**
     * Delete a user and their relationships.
     */
    public function delete(User $user): void;

    /**
     * Assign a tenant membership to a user.
     */
    public function assignTenant(User $user, Tenant $tenant, string $role = 'member'): void;

    /**
     * Remove a user's tenant membership.
     */
    public function removeTenant(User $user, Tenant $tenant): void;

    /**
     * Add a user to a group.
     */
    public function addToGroup(User $user, Group $group): void;

    /**
     * Remove a user from a group.
     */
    public function removeFromGroup(User $user, Group $group): void;

    /**
     * Replace a user's tenant memberships and group assignments.
     *
     * @param  array<int, int>  $tenantIds
     * @param  array<int, string>  $groupIds
     */
    public function syncAssignments(User $user, array $tenantIds, array $groupIds): User;

    /**
     * Get all users.
     *
     * @return Collection<int, User>
     */
    public function all(): Collection;

    /**
     * Find a user by email address.
     */
    public function findByEmail(string $email): ?User;
}
