<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\SipTrunks\Livewire\SipTrunksEdit;
use Modules\SipTrunks\Livewire\SipTrunksList;
use Modules\SipTrunks\Models\SipTrunk;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('denies tenant user access to another tenant sip trunk on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['sip-trunks.view', 'sip-trunks.edit']);
    $foreignTrunk = SipTrunk::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Trunk',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(SipTrunksEdit::class, ['trunkId' => $foreignTrunk->id])
        ->assertForbidden();
});

it('allows tenant user access to their own tenant sip trunk on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['sip-trunks.view', 'sip-trunks.edit']);
    $ownTrunk = SipTrunk::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Tenant A Trunk',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(SipTrunksEdit::class, ['trunkId' => $ownTrunk->id])
        ->assertOk()
        ->assertSet('name', 'Tenant A Trunk')
        ->assertSet('trunkId', $ownTrunk->id);
});

it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['sip-trunks.create']);

    $this->actingAs($userA, 'web');

    expect(function () {
        SipTrunk::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Spoofed Trunk',
            'host' => 'sip.spoof.com',
            'port' => 5060,
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect(SipTrunk::withoutGlobalScope('tenant')->where('name', 'Spoofed Trunk')->exists())->toBeFalse();
});

it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['sip-trunks.edit']);
    $foreignTrunk = SipTrunk::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Original Name',
    ]);

    $this->actingAs($userA, 'web');

    expect(function () use ($foreignTrunk) {
        $foreignTrunk->update([
            'name' => 'Hacked Name',
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect($foreignTrunk->fresh()->name)->toBe('Original Name');
});

it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['sip-trunks.view', 'sip-trunks.delete']);
    $foreignTrunk = SipTrunk::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Foreign Trunk',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(SipTrunksList::class)
        ->call('deleteTrunk', $foreignTrunk->id)
        ->assertForbidden();

    expect(SipTrunk::withoutGlobalScope('tenant')->where('id', $foreignTrunk->id)->exists())->toBeTrue();
});

it('allows two tenants to share the same sip trunk name without collisions', function () {
    $trunkA = SipTrunk::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Primary Carrier',
    ]);

    $trunkB = SipTrunk::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Primary Carrier',
    ]);

    expect($trunkA->id)->not->toBe($trunkB->id)
        ->and($trunkA->name)->toBe($trunkB->name)
        ->and($trunkA->tenant_id)->toBe($this->tenantA->id)
        ->and($trunkB->tenant_id)->toBe($this->tenantB->id);
});
