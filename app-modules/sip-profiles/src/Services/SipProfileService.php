<?php

declare(strict_types=1);

namespace Modules\SipProfiles\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Gateways\Services\GatewayServiceInterface;
use Modules\SipProfiles\Models\SipProfile;

/**
 * Service implementation for managing SIP profiles.
 */
class SipProfileService implements SipProfileServiceInterface
{
    /**
     * Create a new SIP profile service.
     */
    public function __construct(
        private readonly GatewayServiceInterface $gatewayService,
    ) {}

    /**
     * Create a new SIP profile.
     */
    public function create(array $data): SipProfile
    {
        return SipProfile::create($data);
    }

    /**
     * Update an existing SIP profile.
     */
    public function update(SipProfile $profile, array $data): SipProfile
    {
        $profile->update($data);

        return $profile->fresh();
    }

    /**
     * Delete a SIP profile.
     */
    public function delete(SipProfile $profile): void
    {
        $profile->delete();
    }

    /**
     * Enable a SIP profile.
     */
    public function enable(SipProfile $profile): void
    {
        $profile->update(['enabled' => true]);
    }

    /**
     * Disable a SIP profile.
     */
    public function disable(SipProfile $profile): void
    {
        $profile->update(['enabled' => false]);
    }

    /**
     * Get all SIP profiles for a tenant.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return SipProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get();
    }

    /**
     * Generate an XML configuration string for FreeSWITCH SIP profile.
     *
     * Includes profile params from sip_profiles.settings plus gateway
     * definitions for gateways assigned to this profile.
     */
    public function generateConfig(SipProfile $profile): string
    {
        $settings = $profile->settings ?? [];
        $xml = '<profile name="'.e($profile->name).'">'."\n";
        $xml .= '  <settings>'."\n";

        // Profile params
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $xml .= '    <param name="'.e($key).'" value="'.e(implode(',', $value)).'"/>'."\n";
            } else {
                $xml .= '    <param name="'.e($key).'" value="'.e((string) $value).'"/>'."\n";
            }
        }

        $xml .= '  </settings>'."\n";

        // Gateway definitions for this profile
        $gateways = $this->gatewayService->getByTenantAndProfile(
            $profile->tenant_id,
            $profile->name,
        );

        if ($gateways->isNotEmpty()) {
            $xml .= "\n".'    <gateways>'."\n";

            foreach ($gateways as $gateway) {
                $xml .= $this->gatewayService->generateSofiaXml($gateway);
            }

            $xml .= '    </gateways>'."\n";
        }

        $xml .= '</profile>';

        return $xml;
    }
}
