<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Gateways\Livewire\GatewaysEdit;
use Modules\Gateways\Livewire\GatewaysList;
use Modules\Gateways\Models\Gateway;
use Modules\Gateways\Services\GatewayServiceInterface;
use Modules\SipProfiles\Models\SipProfile;
use Modules\SipProfiles\Services\SipProfileServiceInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('Service Operations', function () {
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
});

describe('Tenant Isolation & Boundaries', function () {
    beforeEach(function () {
        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
    });

    afterEach(function () {
        app(TenantManager::class)->setTenantId(null);
    });

    it('denies tenant user access to another tenant gateway on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['gateways.view', 'gateways.edit']);
        $foreignGateway = Gateway::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Gateway',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(GatewaysEdit::class, ['gatewayId' => $foreignGateway->id])
            ->assertForbidden();
    });

    it('allows tenant user access to their own tenant gateway on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['gateways.view', 'gateways.edit']);
        $ownGateway = Gateway::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tenant A Gateway',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(GatewaysEdit::class, ['gatewayId' => $ownGateway->id])
            ->assertOk()
            ->assertSet('name', 'Tenant A Gateway')
            ->assertSet('gatewayId', $ownGateway->id);
    });

    it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['gateways.create']);

        $this->actingAs($userA, 'web');

        expect(function () {
            Gateway::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Spoofed Gateway',
                'host' => 'sip.spoof.com',
                'port' => 5060,
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect(Gateway::withoutGlobalScope('tenant')->where('name', 'Spoofed Gateway')->exists())->toBeFalse();
    });

    it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['gateways.edit']);
        $foreignGateway = Gateway::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Original Gateway',
        ]);

        $this->actingAs($userA, 'web');

        expect(function () use ($foreignGateway) {
            $foreignGateway->update([
                'name' => 'Hacked Gateway',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect($foreignGateway->fresh()->name)->toBe('Original Gateway');
    });

    it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['gateways.view', 'gateways.delete']);
        $foreignGateway = Gateway::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Foreign Gateway',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(GatewaysList::class)
            ->call('deleteGateway', $foreignGateway->id)
            ->assertForbidden();

        expect(Gateway::withoutGlobalScope('tenant')->where('id', $foreignGateway->id)->exists())->toBeTrue();
    });

    it('allows two tenants to have gateways with the same name without collision', function () {
        $gwA = Gateway::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Primary Carrier',
        ]);

        $gwB = Gateway::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Primary Carrier',
        ]);

        expect($gwA->id)->not->toBe($gwB->id)
            ->and($gwA->name)->toBe($gwB->name)
            ->and($gwA->tenant_id)->toBe($this->tenantA->id)
            ->and($gwB->tenant_id)->toBe($this->tenantB->id);
    });
});
