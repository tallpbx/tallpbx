<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Modules\SipTrunks\Livewire\SipTrunksEdit;
use Modules\SipTrunks\Livewire\SipTrunksList;
use Modules\SipTrunks\Models\SipTrunk;
use Modules\SipTrunks\Services\SipTrunkServiceInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('Service Operations', function () {
    beforeEach(function () {
        $this->service = app(SipTrunkServiceInterface::class);
        $this->tenant = Tenant::factory()->create();
    });

    it('creates a sip trunk via service', function () {
        $trunk = $this->service->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Primary Carrier',
            'host' => 'trunk.carrier.com',
            'port' => 5060,
            'username' => 'carrier_user',
            'password' => 'super_secret',
            'codecs' => 'PCMU,PCMA,G722',
            'enabled' => true,
        ]);

        expect($trunk)
            ->toBeInstanceOf(SipTrunk::class)
            ->name->toBe('Primary Carrier')
            ->host->toBe('trunk.carrier.com')
            ->port->toBe(5060)
            ->username->toBe('carrier_user')
            ->codecs->toBe('PCMU,PCMA,G722')
            ->enabled->toBeTrue();

        // Password should be encrypted in DB
        $this->assertDatabaseHas('sip_trunks', [
            'id' => $trunk->id,
            'name' => 'Primary Carrier',
            'host' => 'trunk.carrier.com',
        ]);
    });

    it('finds a sip trunk by id', function () {
        $trunk = SipTrunk::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Findable Trunk',
        ]);

        $found = $this->service->find($trunk->id);

        expect($found->id)->toBe($trunk->id)
            ->and($found->name)->toBe('Findable Trunk');
    });

    it('throws exception when trunk id not found', function () {
        expect(fn () => $this->service->find('non-existent-uuid'))
            ->toThrow(ModelNotFoundException::class);
    });

    it('updates an existing sip trunk via service', function () {
        $trunk = SipTrunk::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Old Name',
            'port' => 5060,
        ]);

        $updated = $this->service->update($trunk, [
            'name' => 'New Name',
            'port' => 5080,
        ]);

        expect($updated->name)->toBe('New Name')
            ->and($updated->port)->toBe(5080);

        $this->assertDatabaseHas('sip_trunks', [
            'id' => $trunk->id,
            'name' => 'New Name',
            'port' => 5080,
        ]);
    });

    it('deletes a sip trunk via service', function () {
        $trunk = SipTrunk::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->service->delete($trunk);

        $this->assertModelMissing($trunk);
    });

    it('retrieves all sip trunks ordered by name', function () {
        SipTrunk::factory()->create(['name' => 'Zeta Carrier']);
        SipTrunk::factory()->create(['name' => 'Alpha Carrier']);
        SipTrunk::factory()->create(['name' => 'Beta Carrier']);

        $all = $this->service->all();

        expect($all->pluck('name')->toArray())
            ->toBe(['Alpha Carrier', 'Beta Carrier', 'Zeta Carrier']);
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
});
