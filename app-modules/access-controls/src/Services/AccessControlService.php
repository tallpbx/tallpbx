<?php

declare(strict_types=1);

namespace Modules\AccessControls\Services;

use App\Support\AclConfigurationCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AccessControls\Models\AccessControl;

/**
 * Service implementation for managing access controls with cascade
 * deletion of nodes.
 */
class AccessControlService implements AccessControlServiceInterface
{
    /**
     * Create a new access control rule with its nodes.
     */
    public function create(array $data): AccessControl
    {
        $this->validateUniqueName($data['tenant_id'], $data['name']);

        $rule = DB::transaction(function () use ($data) {
            $nodes = $data['nodes'] ?? [];
            unset($data['nodes']);

            $rule = AccessControl::create($data);

            foreach ($nodes as $node) {
                $rule->nodes()->create($node);
            }

            return $rule->load('nodes');
        });

        // Keep the served acl.conf fresh: drop the cache and reload FreeSWITCH ACLs.
        AclConfigurationCache::invalidateAndReload();

        return $rule;
    }

    /**
     * Update an existing access control rule.
     */
    public function update(AccessControl $rule, array $data): AccessControl
    {
        if (isset($data['name']) && $data['name'] !== $rule->name) {
            $tenantId = $data['tenant_id'] ?? $rule->tenant_id;
            $this->validateUniqueName($tenantId, $data['name'], $rule->id);
        }

        $rule->update($data);

        $rule = $rule->fresh();

        // Keep the served acl.conf fresh: drop the cache and reload FreeSWITCH ACLs.
        AclConfigurationCache::invalidateAndReload();

        return $rule;
    }

    /**
     * Delete an access control rule and its nodes.
     */
    public function delete(AccessControl $rule): void
    {
        DB::transaction(function () use ($rule) {
            $rule->nodes()->delete();
            $rule->delete();
        });

        // Keep the served acl.conf fresh: drop the cache and reload FreeSWITCH ACLs.
        AclConfigurationCache::invalidateAndReload();
    }

    public function getByTenant(int $tenantId): Collection
    {
        return AccessControl::withoutGlobalScope('tenant')->with('nodes')->where('tenant_id', $tenantId)->get();
    }

    /**
     * Ensure the rule name is unique within the tenant.
     *
     * @throws ValidationException
     */
    private function validateUniqueName(int $tenantId, string $name, ?string $excludeId = null): void
    {
        $query = AccessControl::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('name', $name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['An access control rule with this name already exists in this tenant.'],
            ]);
        }
    }
}
