<?php

declare(strict_types=1);

namespace Modules\CallBlocks\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\CallBlocks\Models\CallBlock;

/**
 * Service interface for call block CRUD operations.
 */
interface CallBlockServiceInterface
{
    /**
     * Create a new call block rule.
     */
    public function create(array $data): CallBlock;

    /**
     * Update an existing call block rule and return the fresh copy.
     */
    public function update(CallBlock $block, array $data): CallBlock;

    /**
     * Delete a call block rule.
     */
    public function delete(CallBlock $block): void;

    /**
     * Get every call block rule for one tenant, ordered by name.
     */
    public function getByTenant(int $tenantId): Collection;
}
