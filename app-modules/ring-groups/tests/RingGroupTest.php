<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\RingGroups\Livewire\RingGroupsEdit;
use Modules\RingGroups\Livewire\RingGroupsList;
use Modules\RingGroups\Models\RingGroup;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the ring groups list component', function () {
    RingGroup::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsList::class)
        ->assertOk()
        ->assertSee('Ring Groups')
        ->assertViewHas('ringGroups', function ($ringGroups) {
            return $ringGroups->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('name', '')
        ->assertSet('strategy', 'ring-all')
        ->assertSet('enabled', true);
});

it('creates a new ring group with extensions', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Sales Team')
        ->set('strategy', 'ring-all')
        ->set('ringTimeout', 30)
        ->call('addExtension')
        ->set('extensions.0.extension_uuid', '1000')
        ->call('addExtension')
        ->set('extensions.1.extension_uuid', '1001')
        ->call('save')
        ->assertRedirect(route('panel.ring-groups.index'));

    $this->assertDatabaseHas('ring_groups', [
        'name' => 'Sales Team',
    ]);

    $ringGroup = RingGroup::withoutGlobalScope('tenant')->where('name', 'Sales Team')->first();
    $this->assertDatabaseHas('ring_group_extensions', [
        'ring_group_id' => $ringGroup->id,
        'extension_uuid' => '1000',
        'position' => 1,
    ]);
    $this->assertDatabaseHas('ring_group_extensions', [
        'ring_group_id' => $ringGroup->id,
        'extension_uuid' => '1001',
        'position' => 2,
    ]);
});

it('updates an existing ring group', function () {
    $ringGroup = RingGroup::factory()->create(['name' => 'Old Team']);
    $ringGroup->extensions()->create([
        'extension_uuid' => '2000',
        'position' => 1,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsEdit::class, ['ringGroupId' => $ringGroup->id])
        ->set('name', 'Updated Team')
        ->set('strategy', 'sequential')
        ->call('save')
        ->assertRedirect(route('panel.ring-groups.index'));

    $this->assertDatabaseHas('ring_groups', [
        'id' => $ringGroup->id,
        'name' => 'Updated Team',
        'strategy' => 'sequential',
    ]);
});

it('deletes a ring group and cascades to extensions', function () {
    $ringGroup = RingGroup::factory()->create();
    $ringGroup->extensions()->create([
        'extension_uuid' => '3000',
        'position' => 1,
    ]);
    $extensionId = $ringGroup->extensions()->first()->id;

    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsList::class)
        ->call('deleteRingGroup', $ringGroup->id)
        ->assertDispatched('ring-group-deleted');

    $this->assertModelMissing($ringGroup);
    $this->assertDatabaseMissing('ring_group_extensions', ['id' => $extensionId]);
});

it('opens the shared confirmation modal before deleting a ring group', function (): void {
    $ringGroup = RingGroup::factory()->create(['name' => 'Sales team']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsList::class)
        ->call('confirmRingGroupDeletion', $ringGroup->id)
        ->assertSet('pendingDeletionId', $ringGroup->id)
        ->assertSet('pendingDeletionName', 'Sales team')
        ->assertSee('Delete Ring Group?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates strategy is required and valid', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsEdit::class)
        ->set('strategy', '')
        ->call('save')
        ->assertHasErrors(['strategy' => 'required']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsEdit::class)
        ->set('strategy', 'invalid')
        ->call('save')
        ->assertHasErrors(['strategy' => 'in']);
});

it('shows empty state when no ring groups exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RingGroupsList::class)
        ->assertSee('No ring groups found.');
});
