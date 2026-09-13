<?php

declare(strict_types=1);

namespace Modules\CallForwards\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\CallForwards\Models\CallForward;

/**
 * Service interface for call forward CRUD operations.
 */
interface CallForwardServiceInterface
{
    /**
     * Create a new call forward rule.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CallForward;

    /**
     * Update an existing call forward rule.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CallForward $forward, array $data): CallForward;

    /**
     * Delete a call forward rule.
     */
    public function delete(CallForward $forward): void;

    /**
     * Get all call forward rules for a given tenant.
     *
     * @return Collection<int, CallForward>
     */
    public function getByTenant(int $tenantId): Collection;
}
