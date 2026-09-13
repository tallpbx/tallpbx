<?php

declare(strict_types=1);

namespace Modules\SipProfiles\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\SipProfiles\Models\SipProfile;

/**
 * Service for managing FreeSWITCH Sofia SIP profiles.
 */
interface SipProfileServiceInterface
{
    /**
     * Create a new SIP profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SipProfile;

    /**
     * Update an existing SIP profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SipProfile $profile, array $data): SipProfile;

    /**
     * Delete a SIP profile.
     */
    public function delete(SipProfile $profile): void;

    /**
     * Enable a SIP profile.
     */
    public function enable(SipProfile $profile): void;

    /**
     * Disable a SIP profile.
     */
    public function disable(SipProfile $profile): void;

    /**
     * Get all profiles for a specific tenant.
     *
     * @return Collection<int, SipProfile>
     */
    public function getByTenant(int $tenantId): Collection;

    /**
     * Generate FreeSWITCH XML configuration for a profile.
     */
    public function generateConfig(SipProfile $profile): string;
}
