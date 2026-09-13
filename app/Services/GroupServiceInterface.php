<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service for managing user groups and group-permission assignments.
 */
interface GroupServiceInterface
{
    /**
     * Create a new group.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Group;

    /**
     * Update an existing group.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Group $group, array $data): Group;

    /**
     * Delete a group and its pivot relationships.
     */
    public function delete(Group $group): void;

    /**
     * Add a user to a group.
     */
    public function addUser(Group $group, User $user): void;

    /**
     * Remove a user from a group.
     */
    public function removeUser(Group $group, User $user): void;

    /**
     * Add a permission to a group.
     */
    public function addPermission(Group $group, Permission $permission): void;

    /**
     * Remove a permission from a group.
     */
    public function removePermission(Group $group, Permission $permission): void;

    /**
     * Sync the permissions for a group to the given array of permission IDs.
     *
     * @param  list<int>  $permissionIds
     */
    public function syncPermissions(Group $group, array $permissionIds): void;

    /**
     * Get all groups for a specific tenant.
     *
     * @return Collection<int, Group>
     */
    public function getByTenant(int $tenantId): Collection;

    /**
     * Get all system-level groups (no tenant).
     *
     * @return Collection<int, Group>
     */
    public function getSystem(): Collection;
}
