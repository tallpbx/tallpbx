<?php

declare(strict_types=1);

namespace Modules\AccessControls\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\AccessControls\Models\AccessControl;

/**
 * Service for managing network-level access control rules with CIDR/IP nodes.
 */
interface AccessControlServiceInterface
{
    /**
     * Create a new access control rule, optionally with nodes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): AccessControl;

    /**
     * Update an existing access control rule.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AccessControl $rule, array $data): AccessControl;

    /**
     * Delete an access control rule along with its nodes.
     */
    public function delete(AccessControl $rule): void;

    /**
     * Get all access controls for a specific tenant.
     *
     * @return Collection<int, AccessControl>
     */
    public function getByTenant(int $tenantId): Collection;
}
