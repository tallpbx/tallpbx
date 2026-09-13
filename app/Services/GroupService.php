<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Concrete implementation of GroupServiceInterface.
 *
 * Manages group CRUD, user-group membership, and group-permission
 * assignments. All multi-step operations are wrapped in database
 * transactions.
 */
class GroupService implements GroupServiceInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $data): Group
    {
        return Group::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function update(Group $group, array $data): Group
    {
        $group->update($data);

        return $group->fresh();
    }

    /**
     * {@inheritdoc}
     */
    public function delete(Group $group): void
    {
        DB::transaction(function () use ($group) {
            $group->users()->detach();
            $group->permissions()->detach();
            $group->delete();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function addUser(Group $group, User $user): void
    {
        $group->users()->syncWithoutDetaching([$user->id]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeUser(Group $group, User $user): void
    {
        $group->users()->detach($user->id);
    }

    /**
     * {@inheritdoc}
     */
    public function addPermission(Group $group, Permission $permission): void
    {
        $group->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /**
     * {@inheritdoc}
     */
    public function removePermission(Group $group, Permission $permission): void
    {
        $group->permissions()->detach($permission->id);
    }

    /**
     * {@inheritdoc}
     */
    public function syncPermissions(Group $group, array $permissionIds): void
    {
        $group->permissions()->sync($permissionIds);
    }

    /**
     * {@inheritdoc}
     */
    public function getByTenant(int $tenantId): Collection
    {
        return Group::where('tenant_id', $tenantId)
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function getSystem(): Collection
    {
        return Group::whereNull('tenant_id')
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }
}
