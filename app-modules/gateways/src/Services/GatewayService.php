<?php

declare(strict_types=1);

namespace Modules\Gateways\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Gateways\Models\Gateway;

/**
 * Service implementation for managing gateways with tenant-scoped
 * uniqueness validation.
 */
class GatewayService implements GatewayServiceInterface
{
    /**
     * Create a new gateway.
     */
    public function create(array $data): Gateway
    {
        $this->validateUniqueName($data['tenant_id'], $data['name']);

        return Gateway::create($data);
    }

    /**
     * Update an existing gateway.
     */
    public function update(Gateway $gateway, array $data): Gateway
    {
        if (isset($data['name']) && $data['name'] !== $gateway->name) {
            $tenantId = $data['tenant_id'] ?? $gateway->tenant_id;
            $this->validateUniqueName($tenantId, $data['name'], $gateway->id);
        }

        $gateway->update($data);

        return $gateway->fresh();
    }

    /**
     * Delete a gateway.
     */
    public function delete(Gateway $gateway): void
    {
        $gateway->delete();
    }

    /**
     * Get all gateways for a tenant.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return Gateway::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get();
    }

    /**
     * Get all enabled gateways for a tenant matching a Sofia profile.
     *
     * Handles the null/empty profile fallback to 'external' by
     * matching gateways where profile IS NULL, is empty, or matches
     * the requested profile name.
     */
    public function getByTenantAndProfile(int $tenantId, string $profile): Collection
    {
        $effectiveProfile = $profile !== '' ? $profile : 'external';

        return Gateway::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where(function ($query) use ($effectiveProfile) {
                $query->where('profile', $effectiveProfile)
                    ->orWhereNull('profile')
                    ->orWhere('profile', '');
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Generate Sofia gateway XML for a FreeSWITCH gateway definition.
     *
     * Merges defaults, settings, and explicit columns. Uses the
     * gateway UUID as the Sofia gateway name for stable references
     * that survive display-name changes.
     */
    public function generateSofiaXml(Gateway $gateway): string
    {
        // Build the effective params via merge order:
        //   1. config defaults (lowest priority)
        //   2. gateway.settings (middle)
        //   3. explicit columns (highest priority)
        $params = config('freeswitch.gateway_defaults', []);
        $settings = $gateway->settings ?? [];

        // Merge settings over defaults
        foreach ($settings as $key => $value) {
            $params[$key] = $value;
        }

        // Explicit columns override settings
        if ($gateway->realm !== null && $gateway->realm !== '') {
            $params['realm'] = $gateway->realm;
        }

        // Build proxy from explicit proxy or host:port
        if ($gateway->proxy !== null && $gateway->proxy !== '') {
            $params['proxy'] = $gateway->proxy;
        } else {
            $params['proxy'] = $gateway->host.':'.$gateway->port;
        }

        if ($gateway->username !== null && $gateway->username !== '') {
            $params['username'] = $gateway->username;
        }

        // Password is hidden from serialization but must appear in XML.
        // Use the decrypted accessor which handles the encrypted cast.
        if ($gateway->password !== null && $gateway->password !== '') {
            $params['password'] = $gateway->password;
        }

        if ($gateway->context !== '') {
            $params['context'] = $gateway->context;
        }

        $params['register'] = $gateway->register ? 'true' : 'false';

        // Remove realm if it would conflict with an explicit proxy
        // (FreeSWITCH uses realm for registration domain; proxy for
        // outbound connection — they can coexist)

        // Use gateway UUID as the Sofia gateway name for stability
        $gatewayName = htmlspecialchars($gateway->id, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '        <gateway name="'.$gatewayName.'">'."\n";

        // Render params in deterministic order
        ksort($params);

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $safeKey = htmlspecialchars($key, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            if (is_bool($value)) {
                $safeValue = $value ? 'true' : 'false';
            } else {
                $safeValue = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }

            $xml .= '          <param name="'.$safeKey.'" value="'.$safeValue.'"/>'."\n";
        }

        $xml .= '        </gateway>'."\n";

        return $xml;
    }

    /**
     * Ensure the gateway name is unique within the tenant.
     *
     * @throws ValidationException
     */
    private function validateUniqueName(int $tenantId, string $name, ?string $excludeId = null): void
    {
        $query = Gateway::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('name', $name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A gateway with this name already exists in this tenant.'],
            ]);
        }
    }
}
