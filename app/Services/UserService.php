<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Concrete implementation of UserServiceInterface.
 *
 * Manages user CRUD, tenant memberships, and group assignments.
 * All multi-step operations are wrapped in database transactions.
 */
class UserService implements UserServiceInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return User::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function update(User $user, array $data): User
    {
        if (isset($data['password']) && $data['password'] !== '') {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return $user->fresh();
    }

    /**
     * {@inheritdoc}
     */
    public function saveWithAssignments(?User $user, array $data, array $tenantIds, array $groupIds): User
    {
        return DB::transaction(function () use ($user, $data, $tenantIds, $groupIds): User {
            $savedUser = $user instanceof User
                ? $this->update($user, $data)
                : $this->create($data);

            return $this->syncAssignments($savedUser, $tenantIds, $groupIds);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->tenants()->detach();
            $user->groups()->detach();
            $user->delete();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function assignTenant(User $user, Tenant $tenant, string $role = 'member'): void
    {
        $user->tenants()->syncWithoutDetaching([
            $tenant->id => ['role' => $role],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeTenant(User $user, Tenant $tenant): void
    {
        $user->tenants()->detach($tenant->id);
    }

    /**
     * {@inheritdoc}
     */
    public function addToGroup(User $user, Group $group): void
    {
        $user->groups()->syncWithoutDetaching([$group->id]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeFromGroup(User $user, Group $group): void
    {
        $user->groups()->detach($group->id);
    }

    /**
     * {@inheritdoc}
     */
    public function syncAssignments(User $user, array $tenantIds, array $groupIds): User
    {
        return DB::transaction(function () use ($user, $tenantIds, $groupIds): User {
            $tenantSync = [];

            foreach (array_values($tenantIds) as $index => $tenantId) {
                $tenantSync[(int) $tenantId] = [
                    'role' => 'member',
                    'primary' => $index === 0,
                ];
            }

            $user->tenants()->sync($tenantSync);
            $user->groups()->sync($groupIds);

            return $user->fresh(['tenants', 'groups']);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function all(): Collection
    {
        return User::query()
            ->with(['tenants', 'groups'])
            ->orderBy('name')
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }
}
