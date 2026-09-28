<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Gateways\Livewire\GatewaysEdit;
use Modules\Gateways\Livewire\GatewaysList;
use Modules\Gateways\Models\Gateway;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
