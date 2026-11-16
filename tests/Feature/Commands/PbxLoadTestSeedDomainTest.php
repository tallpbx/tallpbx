<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\File;

/*
 * A literal default realm in the seeder silently produces a tenant whose
 * SIP domain does not match the PBX host, so every generated REGISTER is
 * rejected. The realm must come from the operator or from configuration,
 * and an empty realm must fail loudly instead of seeding unusable data.
 */

beforeEach(function (): void {
    $this->csv = sys_get_temp_dir().'/pbx-load-test-realm-'.uniqid().'.csv';
});

afterEach(function (): void {
    File::delete($this->csv);
});

it('uses the configured SIP realm when the domain option is omitted', function (): void {
    config(['freeswitch.default_sip_realm' => 'realm.example.test']);

    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-realm',
        '--extensions' => '2',
        '--start' => '3300',
        '--output' => $this->csv,
    ])->assertSuccessful();

    $tenant = Tenant::where('slug', 'load-test-realm')->firstOrFail();

    expect(TenantDomain::query()
        ->where('tenant_id', $tenant->id)
        ->where('domain', 'realm.example.test')
        ->exists())->toBeTrue();
});

it('refuses to seed when neither the option nor the configuration names a realm', function (): void {
    config(['freeswitch.default_sip_realm' => null]);

    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-no-realm',
        '--extensions' => '2',
        '--start' => '3400',
        '--output' => $this->csv,
    ])->assertFailed();

    expect(Tenant::where('slug', 'load-test-no-realm')->exists())->toBeFalse()
        ->and(File::exists($this->csv))->toBeFalse();
});
