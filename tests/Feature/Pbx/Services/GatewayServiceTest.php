<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\Gateways\Models\Gateway;
use Modules\Gateways\Services\GatewayServiceInterface;
use Modules\SipProfiles\Models\SipProfile;
use Modules\SipProfiles\Services\SipProfileServiceInterface;

beforeEach(function () {
    $this->service = app(GatewayServiceInterface::class);
});

it('creates a gateway', function () {
    $tenant = Tenant::factory()->create();

    $gateway = $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'ITSP Outbound',
        'description' => 'Main outbound trunk',
        'host' => 'sip.itsp.com',
        'port' => 5060,
        'username' => 'myuser',
        'password' => 'secret',
        'register' => true,
        'enabled' => true,
    ]);

    expect($gateway)
        ->toBeInstanceOf(Gateway::class)
        ->name->toBe('ITSP Outbound')
        ->host->toBe('sip.itsp.com')
        ->port->toBe(5060)
        ->username->toBe('myuser')
        ->register->toBeTrue();
});

it('updates a gateway', function () {
    $gateway = Gateway::factory()->create(['name' => 'Old']);

    $updated = $this->service->update($gateway, [
        'name' => 'New Gateway',
        'host' => 'new.itsp.com',
    ]);

    expect($updated->name)->toBe('New Gateway')
        ->and($updated->host)->toBe('new.itsp.com');
});

it('deletes a gateway', function () {
    $gateway = Gateway::factory()->create();

    $this->service->delete($gateway);

    $this->assertModelMissing($gateway);
});

it('enforces unique gateway name per tenant', function () {
    $tenant = Tenant::factory()->create();

    $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Primary',
        'host' => 'sip.itsp.com',
    ]);

    $this->expectException(ValidationException::class);

    $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Primary',
        'host' => 'sip2.itsp.com',
    ]);
});

it('returns gateways scoped by tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    Gateway::factory()->forTenant($tenant1->id)->count(2)->create();
    Gateway::factory()->forTenant($tenant2->id)->count(3)->create();

    expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
});

// ─── Phase 5: Gateway Sofia XML Generation ───────────────────────

it('generates gateway XML with config defaults', function () {
    $gateway = Gateway::factory()->create([
        'host' => 'sip.provider.com',
        'port' => 5060,
        'enabled' => true,
    ]);

    $xml = $this->service->generateSofiaXml($gateway);

    expect($xml)
        ->toContain('<gateway name="'.$gateway->id.'"')
        ->toContain('name="expire-seconds"')
        ->toContain('name="retry-seconds"')
        ->toContain('name="dtmf-type"')
        ->toContain('name="codec-prefs"')
        ->toContain('name="proxy"');
});

it('uses explicit columns over config defaults', function () {
    $gateway = Gateway::factory()->create([
        'host' => 'sip.provider.com',
        'port' => 5080,
        'realm' => 'my.realm.com',
        'username' => 'myuser',
        'context' => 'internal',
        'enabled' => true,
    ]);

    $xml = $this->service->generateSofiaXml($gateway);

    expect($xml)
        ->toContain('name="realm" value="my.realm.com"')
        ->toContain('name="username" value="myuser"')
        ->toContain('name="context" value="internal"')
        ->toContain('name="proxy" value="sip.provider.com:5080"');
});

it('password appears in XML but is hidden from JSON serialization', function () {
    $gateway = Gateway::factory()->create([
        'host' => 'sip.provider.com',
        'password' => 'super-secret',
        'enabled' => true,
    ]);

    $xml = $this->service->generateSofiaXml($gateway);

    expect($xml)->toContain('name="password" value="super-secret"');
    expect($gateway->toArray())->not->toHaveKey('password');
});

it('converts register boolean to true/false in XML', function () {
    $gateway = Gateway::factory()->create([
        'host' => 'sip.provider.com',
        'register' => true,
        'enabled' => true,
    ]);

    $xml = $this->service->generateSofiaXml($gateway);

    expect($xml)->toContain('name="register" value="true"')
        ->not->toContain('value="1"');
});

it('treats null profile as external when matching gateways', function () {
    $tenant = Tenant::factory()->create();
    $gateway = Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'profile' => null,
        'enabled' => true,
    ]);
    Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'profile' => 'internal',
        'enabled' => true,
    ]);

    $externalGateways = $this->service->getByTenantAndProfile($tenant->id, 'external');

    expect($externalGateways)->toHaveCount(1)
        ->and($externalGateways->first()->id)->toBe($gateway->id);
});

it('excludes disabled gateways from profile queries', function () {
    $tenant = Tenant::factory()->create();
    Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'profile' => 'external',
        'enabled' => true,
    ]);
    Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'profile' => 'external',
        'enabled' => false,
    ]);

    $gateways = $this->service->getByTenantAndProfile($tenant->id, 'external');

    expect($gateways)->toHaveCount(1);
});

it('excludes gateways from other tenants in profile queries', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    Gateway::factory()->create([
        'tenant_id' => $tenantA->id,
        'profile' => 'external',
        'enabled' => true,
    ]);
    Gateway::factory()->create([
        'tenant_id' => $tenantB->id,
        'profile' => 'external',
        'enabled' => true,
    ]);

    $gateways = $this->service->getByTenantAndProfile($tenantA->id, 'external');

    expect($gateways)->toHaveCount(1)
        ->and($gateways->first()->tenant_id)->toBe($tenantA->id);
});

it('includes gateway XML in SIP profile configuration', function () {
    $tenant = Tenant::factory()->create();
    $profile = SipProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'external',
        'enabled' => true,
    ]);
    Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'my-trunk',
        'host' => 'sip.trunk.com',
        'profile' => 'external',
        'enabled' => true,
    ]);

    $sipService = app(SipProfileServiceInterface::class);
    $xml = $sipService->generateConfig($profile);

    expect($xml)
        ->toContain('<profile name="external">')
        ->toContain('<gateways>')
        ->toContain('<gateway name="')
        ->toContain('</gateways>');
});
