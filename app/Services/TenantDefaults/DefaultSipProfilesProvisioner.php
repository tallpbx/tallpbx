<?php

declare(strict_types=1);

namespace App\Services\TenantDefaults;

use App\Models\Tenant;
use App\Services\DialplanContext;
use Modules\SipProfiles\Models\SipProfile;

/**
 * Creates the baseline Sofia SIP profiles for a tenant.
 */
final class DefaultSipProfilesProvisioner
{
    /**
     * Create the provisioner instance.
     */
    public function __construct(
        private readonly DialplanContext $dialplanContext,
    ) {}

    /**
     * Provision missing SIP profiles for the tenant.
     *
     * @return array{created: int, skipped: int}
     */
    public function provision(Tenant $tenant): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->profiles((string) $tenant->id) as $profile) {
            $exists = SipProfile::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('name', $profile['name'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            SipProfile::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id,
                'name' => $profile['name'],
                'description' => $profile['description'],
                'settings' => $profile['settings'],
                'enabled' => true,
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Return FreeSWITCH-native profile params.
     *
     * @return array<int, array{name: string, description: string, settings: array<string, string>}>
     */
    private function profiles(string $tenantId): array
    {
        $internalContext = $this->dialplanContext->internal($tenantId);
        $publicContext = $this->dialplanContext->public($tenantId);

        return [
            [
                'name' => 'internal',
                'description' => 'Internal registrations and tenant extension traffic.',
                'settings' => [
                    'sip-port' => '5060',
                    'sip-ip' => '$${local_ip_v4}',
                    'rtp-ip' => '$${local_ip_v4}',
                    'ext-sip-ip' => 'auto-nat',
                    'ext-rtp-ip' => 'auto-nat',
                    'context' => $internalContext,
                    'dialplan' => 'XML',
                    'dtmf-type' => 'rfc2833',
                    'inbound-codec-prefs' => 'G722,PCMU,PCMA',
                    'outbound-codec-prefs' => 'G722,PCMU,PCMA',
                    'auth-calls' => 'true',
                    'manage-presence' => 'true',
                    'manage-shared-appearance' => 'true',
                    'presence-hosts' => '$${domain}',
                    'force-register-domain' => '$${domain}',
                    'force-subscription-domain' => '$${domain}',
                    'apply-inbound-acl' => 'domains',
                    'nonce-ttl' => '60',
                    'rtp-timeout-sec' => '300',
                    'rtp-hold-timeout-sec' => '1800',
                    'challenge-realm' => 'auto_from',
                    'accept-blind-reg' => 'false',
                    'accept-blind-auth' => 'false',
                    'inbound-reg-force-matching-username' => 'true',
                    'disable-transcoding' => 'false',
                    'inbound-late-negotiation' => 'true',
                    'send-presence-on-register' => 'true',
                    'tls' => '$${internal_ssl_enable}',
                    'tls-bind-params' => 'transport=tls',
                ],
            ],
            [
                'name' => 'external',
                'description' => 'External gateway and public SIP traffic.',
                'settings' => [
                    'sip-port' => '5080',
                    'sip-ip' => '$${local_ip_v4}',
                    'rtp-ip' => '$${local_ip_v4}',
                    'ext-sip-ip' => 'auto-nat',
                    'ext-rtp-ip' => 'auto-nat',
                    'context' => $publicContext,
                    'dialplan' => 'XML',
                    'dtmf-type' => 'rfc2833',
                    'inbound-codec-prefs' => 'G722,PCMU,PCMA',
                    'outbound-codec-prefs' => 'G722,PCMU,PCMA',
                    'auth-calls' => 'false',
                    'accept-blind-reg' => 'false',
                    'accept-blind-auth' => 'true',
                    'apply-inbound-acl' => 'domains',
                    'nonce-ttl' => '60',
                    'rtp-timeout-sec' => '300',
                    'rtp-hold-timeout-sec' => '1800',
                    'inbound-late-negotiation' => 'true',
                    'disable-transcoding' => 'false',
                    'manage-presence' => 'false',
                    'manage-shared-appearance' => 'false',
                    'enable-100rel' => 'true',
                    'tls' => '$${external_ssl_enable}',
                    'tls-bind-params' => 'transport=tls',
                ],
            ],
        ];
    }
}
