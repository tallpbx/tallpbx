<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\RingGroups\Livewire\RingGroupsEdit;
use Modules\RingGroups\Livewire\RingGroupsList;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Services\RingGroupServiceInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('Service Operations', function () {
    beforeEach(function () {
        $this->service = app(RingGroupServiceInterface::class);
        $this->tenant = Tenant::factory()->create();
    });

    it('creates a ring group with extensions via service', function () {
        $group = $this->service->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Support Desk',
            'strategy' => 'ring-all',
            'ring_timeout' => 30,
            'enabled' => true,
        ], [
            ['extension_uuid' => '1001', 'delay' => 0, 'timeout' => 30],
            ['extension_uuid' => '1002', 'delay' => 5, 'timeout' => 25],
        ]);

        expect($group)->toBeInstanceOf(RingGroup::class)
            ->name->toBe('Support Desk')
            ->strategy->toBe('ring-all')
            ->ring_timeout->toBe(30)
            ->and($group->extensions)->toHaveCount(2);

        $this->assertDatabaseHas('ring_groups', [
            'id' => $group->id,
            'name' => 'Support Desk',
        ]);

        $this->assertDatabaseHas('ring_group_extensions', [
            'ring_group_id' => $group->id,
            'extension_uuid' => '1001',
        ]);
    });

    it('updates a ring group and replaces all its extensions via service', function () {
        $group = $this->service->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Old Group',
            'strategy' => 'ring-all',
            'ring_timeout' => 20,
        ], [
            ['extension_uuid' => '1001', 'delay' => 0, 'timeout' => 20],
        ]);

        $updated = $this->service->update($group, [
            'name' => 'New Group',
            'strategy' => 'sequence',
            'ring_timeout' => 45,
        ], [
            ['extension_uuid' => '2001', 'delay' => 0, 'timeout' => 20],
            ['extension_uuid' => '2002', 'delay' => 20, 'timeout' => 25],
        ]);

        expect($updated->name)->toBe('New Group')
            ->and($updated->strategy)->toBe('sequence')
            ->and($updated->ring_timeout)->toBe(45)
            ->and($updated->extensions)->toHaveCount(2);

        // Old extension 1001 should be gone
        $this->assertDatabaseMissing('ring_group_extensions', [
            'ring_group_id' => $group->id,
            'extension_uuid' => '1001',
        ]);

        // New extension 2001 should exist
        $this->assertDatabaseHas('ring_group_extensions', [
            'ring_group_id' => $group->id,
            'extension_uuid' => '2001',
        ]);
    });

    it('deletes a ring group and cascades its extensions', function () {
        $group = $this->service->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'To Delete',
            'strategy' => 'ring-all',
        ], [
            ['extension_uuid' => '1001', 'delay' => 0, 'timeout' => 30],
        ]);

        $this->service->delete($group);

        $this->assertModelMissing($group);
        $this->assertDatabaseMissing('ring_group_extensions', [
            'ring_group_id' => $group->id,
        ]);
    });

    it('returns dialplan priority of 70', function () {
        expect($this->service->getDialplanPriority())->toBe(70);
    });

    it('retrieves all ring groups with extensions ordered by name', function () {
        $this->service->create(['tenant_id' => $this->tenant->id, 'name' => 'Support Team', 'strategy' => 'ring-all'], []);
        $this->service->create(['tenant_id' => $this->tenant->id, 'name' => 'Billing Team', 'strategy' => 'ring-all'], []);
        $this->service->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales Team', 'strategy' => 'ring-all'], []);

        $all = $this->service->getAll();

        expect($all->pluck('name')->toArray())
            ->toBe(['Billing Team', 'Sales Team', 'Support Team']);
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

    it('denies tenant user access to another tenant ring group on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['ring-groups.view', 'ring-groups.edit']);
        $foreignGroup = RingGroup::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Group',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(RingGroupsEdit::class, ['ringGroupId' => $foreignGroup->id])
            ->assertForbidden();
    });

    it('allows tenant user access to their own tenant ring group on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['ring-groups.view', 'ring-groups.edit']);
        $ownGroup = RingGroup::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tenant A Group',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(RingGroupsEdit::class, ['ringGroupId' => $ownGroup->id])
            ->assertOk()
            ->assertSet('name', 'Tenant A Group')
            ->assertSet('ringGroupId', $ownGroup->id);
    });

    it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['ring-groups.create']);

        $this->actingAs($userA, 'web');

        expect(function () {
            RingGroup::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Spoofed Ring Group',
                'strategy' => 'ring-all',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect(RingGroup::withoutGlobalScope('tenant')->where('name', 'Spoofed Ring Group')->exists())->toBeFalse();
    });

    it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['ring-groups.edit']);
        $foreignGroup = RingGroup::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Original Group',
            'strategy' => 'ring-all',
        ]);

        $this->actingAs($userA, 'web');

        expect(function () use ($foreignGroup) {
            $foreignGroup->update([
                'name' => 'Hacked Group',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect($foreignGroup->fresh()->name)->toBe('Original Group');
    });

    it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['ring-groups.view', 'ring-groups.delete']);
        $foreignGroup = RingGroup::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Foreign Group',
            'strategy' => 'ring-all',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(RingGroupsList::class)
            ->call('deleteRingGroup', $foreignGroup->id)
            ->assertForbidden();

        expect(RingGroup::withoutGlobalScope('tenant')->where('id', $foreignGroup->id)->exists())->toBeTrue();
    });

    it('allows tenant user to create ring group for their own tenant', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['ring-groups.view', 'ring-groups.create']);

        Livewire::actingAs($userA, 'web')
            ->test(RingGroupsEdit::class)
            ->set('name', 'Support Group')
            ->set('strategy', 'ring-all')
            ->set('ringTimeout', 25)
            ->call('save')
            ->assertRedirect(route('panel.ring-groups.index'));

        $group = RingGroup::withoutGlobalScope('tenant')->where('name', 'Support Group')->first();
        expect($group)->not->toBeNull()
            ->and($group->tenant_id)->toBe($this->tenantA->id);
    });

    it('allows two tenants to have ring groups with the same name without collision', function () {
        $groupA = RingGroup::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Sales Team',
            'strategy' => 'ring-all',
        ]);

        $groupB = RingGroup::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Sales Team',
            'strategy' => 'ring-all',
        ]);

        expect($groupA->id)->not->toBe($groupB->id)
            ->and($groupA->name)->toBe($groupB->name)
            ->and($groupA->tenant_id)->toBe($this->tenantA->id)
            ->and($groupB->tenant_id)->toBe($this->tenantB->id);
    });
});
