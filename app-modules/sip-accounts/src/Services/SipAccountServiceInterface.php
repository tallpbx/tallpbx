<?php

declare(strict_types=1);

namespace Modules\SipAccounts\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\SipAccounts\Models\SipAccount;

/**
 * Service for managing SIP authentication accounts with identity mode support.
 */
interface SipAccountServiceInterface
{
    /**
     * Create a new SIP account with identity mode validation.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException when mode constraints are violated
     * @throws ValidationException when uniqueness is violated
     */
    public function create(array $data): SipAccount;

    /**
     * Update an existing SIP account.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SipAccount $account, array $data): SipAccount;

    /**
     * Delete a SIP account.
     */
    public function delete(SipAccount $account): void;

    /**
     * Get all accounts for a specific tenant.
     *
     * @return Collection<int, SipAccount>
     */
    public function getByTenant(int $tenantId): Collection;
}
