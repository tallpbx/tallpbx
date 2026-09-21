<?php

declare(strict_types=1);

namespace Modules\SipTrunks\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\SipTrunks\Models\SipTrunk;

/**
 * Service implementation for SIP trunk management.
 *
 * Wraps all write operations in database transactions.
 * All queries bypass the global tenant scope because this
 * service is used by admin panels that need cross-tenant access.
 */
class SipTrunkService implements SipTrunkServiceInterface
{
    /**
     * Find a SIP trunk by ID, bypassing tenant scope.
     */
    public function find(string $id): SipTrunk
    {
        return SipTrunk::withoutGlobalScope('tenant')->findOrFail($id);
    }

    /**
     * Get all SIP trunks across all tenants, ordered by name.
     */
    public function all(): Collection
    {
        return SipTrunk::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): SipTrunk
    {
        return DB::transaction(fn () => SipTrunk::withoutGlobalScope('tenant')->create($data));
    }

    public function update(SipTrunk $trunk, array $data): SipTrunk
    {
        return DB::transaction(function () use ($trunk, $data): SipTrunk {
            $trunk->update($data);

            return $trunk->fresh();
        });
    }

    public function delete(SipTrunk $trunk): void
    {
        DB::transaction(fn () => $trunk->delete());
    }
}
