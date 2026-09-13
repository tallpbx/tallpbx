<?php

declare(strict_types=1);

namespace Modules\SipTrunks\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\SipTrunks\Models\SipTrunk;

/**
 * Contract for SIP trunk CRUD operations.
 *
 * All operations internally handle tenant-scope bypass
 * where needed so callers never need to call
 * withoutGlobalScope('tenant') directly.
 */
interface SipTrunkServiceInterface
{
    /**
     * Find a SIP trunk by ID, bypassing tenant scope for admin access.
     */
    public function find(string $id): SipTrunk;

    /**
     * Get all SIP trunks across all tenants, ordered by name.
     *
     * @return Collection<int, SipTrunk>
     */
    public function all(): Collection;

    public function create(array $data): SipTrunk;

    public function update(SipTrunk $trunk, array $data): SipTrunk;

    public function delete(SipTrunk $trunk): void;
}
