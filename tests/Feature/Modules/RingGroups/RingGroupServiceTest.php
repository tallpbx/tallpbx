<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Models\RingGroupExtension;
use Modules\RingGroups\Services\RingGroupServiceInterface;

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
