<?php

declare(strict_types=1);

namespace App\Services\TenantDefaults;

use App\Models\Tenant;
use App\Models\TenantDomain;
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
        $realm = $this->sipRealm($tenant);

        foreach ($this->profiles((string) $tenant->id, $realm) as $profile) {
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
     * Return the realm this tenant's endpoints register under.
     *
     * A tenant's SIP domain is the realm its endpoints authenticate against.
     * Null means the tenant has no realm of its own, in which case the
     * profile must not force one: the request's own domain is the only
     * domain the directory can resolve.
     */
    private function sipRealm(Tenant $tenant): ?string
    {
        $realm = TenantDomain::query()
            ->where('tenant_id', $tenant->id)
            ->where('purpose', 'sip_realm')
            ->orderBy('created_at')
            ->value('domain');

        return is_string($realm) && $realm !== '' ? $realm : null;
    }

    /**
     * Return FreeSWITCH-native profile params.
     *
     * @return array<int, array{name: string, description: string, settings: array<string, string>}>
     */
    private function profiles(string $tenantId, ?string $realm): array
    {
        $internalContext = $this->dialplanContext->internal($tenantId);
        $publicContext = $this->dialplanContext->public($tenantId);

        // The registry keeps the appliance-wide `$${domain}` for compatibility
        // with a single-tenant install. A tenant profile cannot use it: it
        // resolves to the appliance address, so FreeSWITCH would look every
        // registration up under a realm the directory has no user for and
        // answer 401/403 to every endpoint. When the tenant has no realm, the
        // keys are omitted entirely so the client's own domain survives.
        $realmSettings = $realm === null ? [] : [
            'force-register-domain' => $realm,
            'force-subscription-domain' => $realm,
            'presence-hosts' => $realm,
        ];

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
                    ...$realmSettings,
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
