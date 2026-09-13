<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GroupServiceInterface;

beforeEach(function () {
    $this->service = app(GroupServiceInterface::class);
});

it('creates a system group', function () {
    $group = $this->service->create([
        'name' => 'Super Admins',
        'description' => 'Platform administrators',
    ]);

    expect($group)
        ->toBeInstanceOf(Group::class)
        ->name->toBe('Super Admins')
        ->description->toBe('Platform administrators')
        ->tenant_id->toBeNull();
});

it('creates a tenant-scoped group', function () {
    $tenant = Tenant::factory()->create();

    $group = $this->service->create([
        'name' => 'Tenant Admins',
        'tenant_id' => $tenant->id,
    ]);

    expect($group)
        ->tenant_id->toBe($tenant->id)
        ->isSystem()->toBeFalse();
});

it('updates a group', function () {
    $group = Group::factory()->system()->create(['name' => 'Old Name']);

    $updated = $this->service->update($group, [
        'name' => 'New Name',
        'description' => 'Updated description',
    ]);

    expect($updated->name)->toBe('New Name')
        ->and($updated->description)->toBe('Updated description');
});

it('deletes a group', function () {
    $group = Group::factory()->system()->create();

    $this->service->delete($group);

    $this->assertModelMissing($group);
});

it('adds a user to a group', function () {
    $group = Group::factory()->system()->create();
    $user = User::factory()->create();

    $this->service->addUser($group, $user);

    expect($group->users()->count())->toBe(1)
        ->and($group->users()->first()->id)->toBe($user->id);
});

it('removes a user from a group', function () {
    $group = Group::factory()->system()->create();
    $user = User::factory()->create();
    $group->users()->attach($user);

    expect($group->users()->count())->toBe(1);

    $this->service->removeUser($group, $user);

    expect($group->users()->count())->toBe(0);
});

it('adds a permission to a group', function () {
    $group = Group::factory()->system()->create();
    $permission = Permission::factory()->create();

    $this->service->addPermission($group, $permission);

    expect($group->permissions()->count())->toBe(1)
        ->and($group->permissions()->first()->id)->toBe($permission->id);
});

it('removes a permission from a group', function () {
    $group = Group::factory()->system()->create();
    $permission = Permission::factory()->create();
    $group->permissions()->attach($permission);

    expect($group->permissions()->count())->toBe(1);

    $this->service->removePermission($group, $permission);

    expect($group->permissions()->count())->toBe(0);
});

it('syncs permissions for a group', function () {
    $group = Group::factory()->system()->create();
    $permA = Permission::factory()->create();
    $permB = Permission::factory()->create();
    $permC = Permission::factory()->create();

    $group->permissions()->attach([$permA->id]);

    $this->service->syncPermissions($group, [$permB->id, $permC->id]);

    $ids = $group->permissions()->pluck('id')->toArray();
    expect($ids)->toEqualCanonicalizing([$permB->id, $permC->id]);
});

it('retrieves groups by tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    Group::factory()->forTenant($tenantA->id)->count(2)->create();
    Group::factory()->forTenant($tenantB->id)->create();
    Group::factory()->system()->create();

    $groupsA = $this->service->getByTenant($tenantA->id);
    $groupsB = $this->service->getByTenant($tenantB->id);
    $systemGroups = $this->service->getSystem();

    expect($groupsA)->toHaveCount(2)
        ->and($groupsB)->toHaveCount(1)
        ->and($systemGroups)->toHaveCount(1);
});
