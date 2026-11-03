<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;

/*
|--------------------------------------------------------------------------
| Appliance Host IP Migration
|--------------------------------------------------------------------------
|
| `appliance:sync-host` runs on every installer pass. After a snapshot is
| restored onto a new address the tenant already owns the new realm while a
| stale row still points at the old one, so a blanket UPDATE hits the
| (domain, purpose, tenant) unique key and aborts the whole install. These
| tests pin the merge behaviour that keeps a re-run idempotent.
|
*/

it('merges a stale realm when the tenant already owns the new address', function (): void {
    $tenant = Tenant::factory()->create();

    TenantDomain::factory()->for($tenant)->sipRealm()->create(['domain' => '146.190.250.145']);
    TenantDomain::factory()->for($tenant)->sipRealm()->create(['domain' => '146.190.245.203']);

    $this->artisan('appliance:sync-host', [
        '--old-ip' => '146.190.250.145',
        '--new-ip' => '146.190.245.203',
    ])->assertSuccessful();

    expect(TenantDomain::where('tenant_id', $tenant->id)->where('purpose', 'sip_realm')->pluck('domain')->all())
        ->toBe(['146.190.245.203']);
});

it('renames a stale realm when the target address is free', function (): void {
    $tenant = Tenant::factory()->create();

    TenantDomain::factory()->for($tenant)->sipRealm()->create(['domain' => '146.190.250.145']);

    $this->artisan('appliance:sync-host', [
        '--old-ip' => '146.190.250.145',
        '--new-ip' => '146.190.245.203',
    ])->assertSuccessful();

    expect(TenantDomain::where('tenant_id', $tenant->id)->where('purpose', 'sip_realm')->pluck('domain')->all())
        ->toBe(['146.190.245.203']);
});

it('leaves every other realm untouched', function (): void {
    $tenant = Tenant::factory()->create();

    TenantDomain::factory()->for($tenant)->sipRealm()->create(['domain' => '146.190.250.145']);
    TenantDomain::factory()->for($tenant)->create(['domain' => 'load.test.local', 'purpose' => 'provisioning']);

    $this->artisan('appliance:sync-host', [
        '--old-ip' => '146.190.250.145',
        '--new-ip' => '146.190.245.203',
    ])->assertSuccessful();

    expect(TenantDomain::where('tenant_id', $tenant->id)->where('purpose', 'provisioning')->pluck('domain')->all())
        ->toBe(['load.test.local']);
});
