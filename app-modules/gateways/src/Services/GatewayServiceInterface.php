<?php

declare(strict_types=1);

namespace Modules\Gateways\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Gateways\Models\Gateway;

/**
 * Service for managing SIP gateway/trunk connections.
 */
interface GatewayServiceInterface
{
    /**
     * Create a new gateway.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Gateway;

    /**
     * Update an existing gateway.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Gateway $gateway, array $data): Gateway;

    /**
     * Delete a gateway.
     */
    public function delete(Gateway $gateway): void;

    /**
     * Get all gateways for a specific tenant.
     *
     * @return Collection<int, Gateway>
     */
    public function getByTenant(int $tenantId): Collection;

    /**
     * Get all enabled gateways for a tenant matching a Sofia profile.
     *
     * A null or empty profile matches gateways with a null/empty
     * profile (both resolve to 'external' at render time).
     *
     * @return Collection<int, Gateway>
     */
    public function getByTenantAndProfile(int $tenantId, string $profile): Collection;

    /**
     * Generate Sofia gateway XML for a FreeSWITCH gateway definition.
     *
     * Merges defaults in order:
     *   1. config('freeswitch.gateway_defaults')
     *   2. gateway.settings (json)
     *   3. explicit model columns (realm, proxy, username, password, etc.)
     *
     * Uses the gateway UUID as the Sofia gateway name for stable
     * references. Password is rendered in XML but hidden from
     * serialization.
     *
     * @return string FreeSWITCH-compatible <gateway> XML
     */
    public function generateSofiaXml(Gateway $gateway): string;
}
