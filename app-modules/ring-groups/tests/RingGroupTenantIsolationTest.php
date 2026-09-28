<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\RingGroups\Livewire\RingGroupsEdit;
use Modules\RingGroups\Livewire\RingGroupsList;
use Modules\RingGroups\Models\RingGroup;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
