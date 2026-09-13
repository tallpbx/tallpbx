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
    public function create(array $data): CallBlock;

    public function update(CallBlock $block, array $data): CallBlock;

    public function delete(CallBlock $block): void;

    public function getByTenant(int $tenantId): Collection;
}
