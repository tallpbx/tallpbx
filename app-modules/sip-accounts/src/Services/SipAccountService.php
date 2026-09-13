<?php

declare(strict_types=1);

namespace Modules\SipAccounts\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\SipAccounts\Models\SipAccount;

/**
 * Service implementation for managing SIP accounts with full identity
 * mode support and validation.
 */
class SipAccountService implements SipAccountServiceInterface
{
    /**
     * Create a new SIP account with identity mode validation.
     */
    public function create(array $data): SipAccount
    {
        $data = $this->validateIdentityMode($data);
        $data['global_auth_key'] = $this->generateGlobalAuthKey($data);

        try {
            return SipAccount::create($data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'auth_username' => ['This SIP account username is already taken.'],
            ]);
        }
    }

    /**
     * Update an existing SIP account.
     */
    public function update(SipAccount $account, array $data): SipAccount
    {
        if (isset($data['auth_username']) || isset($data['identity_mode']) || isset($data['tenant_domain_id'])) {
            $merged = array_merge($account->toArray(), $data);
            $data['global_auth_key'] = $this->generateGlobalAuthKey($merged);
        }

        $account->update($data);

        return $account->fresh();
    }

    /**
     * Delete a SIP account.
     */
    public function delete(SipAccount $account): void
    {
        $account->delete();
    }

    /**
     * Get all SIP accounts for a tenant with their tenant domains.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return SipAccount::withoutGlobalScope('tenant')->with('tenantDomain')->where('tenant_id', $tenantId)->get();
    }

    /**
     * Validate identity mode constraints before creation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private function validateIdentityMode(array $data): array
    {
        $mode = $data['identity_mode'] ?? 'global_username';

        if ($mode === 'global_username') {
            // Global mode: auth_username must be globally unique (handled by global_auth_key)
            $data['tenant_domain_id'] = null;
        } elseif ($mode === 'domain_username') {
            // Domain mode: tenant_domain_id is required
            if (empty($data['tenant_domain_id'])) {
                throw new \InvalidArgumentException('tenant_domain_id is required for domain_username mode.');
            }
        } elseif ($mode === 'hybrid') {
            // Hybrid mode: tenant_domain_id is required
            if (empty($data['tenant_domain_id'])) {
                throw new \InvalidArgumentException('tenant_domain_id is required for hybrid mode.');
            }
        }

        return $data;
    }

    /**
     * Generate the global_auth_key based on identity mode.
     *
     * @param  array<string, mixed>  $data
     */
    private function generateGlobalAuthKey(array $data): string
    {
        $mode = $data['identity_mode'] ?? 'global_username';
        $username = $data['auth_username'];

        if ($mode === 'global_username') {
            return "global:{$username}";
        }

        $domainId = $data['tenant_domain_id'] ?? 'unknown';

        if ($mode === 'domain_username') {
            return "domain:{$domainId}:{$username}";
        }

        return "hybrid:{$domainId}:{$username}";
    }
}
