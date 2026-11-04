<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantDefaults\DefaultSipProfilesProvisioner;
use Modules\SipProfiles\Models\SipProfile;

/*
|--------------------------------------------------------------------------
| Tenant SIP Profile Realm
|--------------------------------------------------------------------------
|
| A tenant's SIP profile is bound to that tenant's realm. The appliance-wide
| variable `$${domain}` resolves to the box's own address, so a profile that
| uses it forces every registration into the appliance realm — and the
| directory has no user there. Every endpoint of every tenant then fails to
| register with 401/403, which reads as a credentials problem but is really a
| realm problem.
|
| These checks pin the tenant's own realm into the profile settings.
|
*/

describe('tenant sip profile realm', function (): void {
    /**
     * Read one profile's settings for a tenant, ignoring the tenant scope.
     *
     * @return array<string, string>
     */
    $settingsFor = function (Tenant $tenant, string $name): array {
        /** @var array<string, string> $settings */
        $settings = SipProfile::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->value('settings');

        return $settings;
    };

    it('publishes the tenant realm instead of the appliance realm', function () use ($settingsFor): void {
        $tenant = Tenant::factory()->create();
        TenantDomain::factory()->for($tenant)->sipRealm()->create(['domain' => 'load-realm.test']);

        app(DefaultSipProfilesProvisioner::class)->provision($tenant);

        $settings = $settingsFor($tenant, 'internal');

        // `$${domain}` is the appliance address. A tenant profile that names it
        // makes every registration resolve to a user that does not exist.
        expect($settings['force-register-domain'])->toBe('load-realm.test')
            ->and($settings['force-subscription-domain'])->toBe('load-realm.test')
            ->and($settings['presence-hosts'])->toBe('load-realm.test');
    });

    it('keeps the appliance realm out of a tenant profile that has no realm of its own', function () use ($settingsFor): void {
        $tenant = Tenant::factory()->create();

        app(DefaultSipProfilesProvisioner::class)->provision($tenant);

        $settings = $settingsFor($tenant, 'internal');

        // With no realm to force, the profile must leave the request's own
        // domain alone: forcing it to the appliance realm guarantees a miss.
        expect($settings)->not->toHaveKey('force-register-domain')
            ->and($settings)->not->toHaveKey('force-subscription-domain')
            ->and($settings)->not->toHaveKey('presence-hosts');
    });

    it('leaves the transport settings of the profile untouched', function () use ($settingsFor): void {
        $tenant = Tenant::factory()->create();
        TenantDomain::factory()->for($tenant)->sipRealm()->create(['domain' => 'load-realm.test']);

        app(DefaultSipProfilesProvisioner::class)->provision($tenant);

        $settings = $settingsFor($tenant, 'internal');

        // The realm is the only thing being corrected here; the port, the
        // context and the authentication posture must not drift with it.
        expect($settings['sip-port'])->toBe('5060')
            ->and($settings['auth-calls'])->toBe('true')
            ->and($settings['apply-inbound-acl'])->toBe('domains')
            ->and($settings['context'])->toContain('tenant_'.$tenant->id);
    });
});
