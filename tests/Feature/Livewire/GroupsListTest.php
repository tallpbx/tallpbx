<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GroupServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Admin\Livewire\GroupsList;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the groups list component', function () {
    Group::factory()->system()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class)
        ->assertOk()
        ->assertSee('Groups')
        ->assertViewHas('groups', function ($groups) {
            return $groups->count() === 3;
        });
});

it('filters groups by tenant', function () {
    $tenant = Tenant::factory()->create();
    Group::factory()->system()->count(2)->create();
    Group::factory()->forTenant($tenant->id)->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class, ['tenantId' => $tenant->id])
        ->call('setFilter', ['tenant_id' => $tenant->id])
        ->assertViewHas('groups', function ($groups) {
            return $groups->count() === 2;
        });
});

it('shows system groups when no tenant filter', function () {
    $tenant = Tenant::factory()->create();
    Group::factory()->system()->count(1)->create();
    Group::factory()->forTenant($tenant->id)->count(3)->create();

    // Without tenant filter, should show system groups
    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class)
        ->assertViewHas('groups', function ($groups) {
            return $groups->count() === 1;
        });
});

it('deletes a group after modal confirmation', function () {
    $group = Group::factory()->system()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class)
        ->call('confirmGroupDeletion', $group->id)
        ->assertSet('pendingDeletionId', $group->id)
        ->call('deleteGroup')
        ->assertSet('operationalMessage', 'Group deleted.')
        ->assertDispatched('group-deleted');

    $this->assertModelMissing($group);
});

it('opens the shared confirmation modal before deleting a group', function (): void {
    $group = Group::factory()->system()->create(['name' => 'Support agents']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class)
        ->call('confirmGroupDeletion', $group->id)
        ->assertSet('pendingDeletionId', $group->id)
        ->assertSet('pendingDeletionName', 'Support agents')
        ->assertSee('Delete Group?');
});

it('keeps the modal open with a safe error when group deletion fails', function (): void {
    $group = Group::factory()->system()->create();

    $service = Mockery::mock(GroupServiceInterface::class);
    $service->shouldReceive('getSystem')->andReturn(new Collection([$group]));
    $service->shouldReceive('delete')->once()->andThrow(new RuntimeException('group still has members'));
    app()->instance(GroupServiceInterface::class, $service);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class)
        ->call('confirmGroupDeletion', $group->id)
        ->call('deleteGroup')
        ->assertSet('deleteError', 'Group could not be deleted. group still has members');

    $this->assertModelExists($group);
});

it('shows user and permission counts for each group', function () {
    $group = Group::factory()->system()->create();
    $user = User::factory()->create();
    $perm = Permission::factory()->create();

    $group->users()->attach($user);
    $group->permissions()->attach($perm);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class)
        ->assertViewHas('groups', function ($groups) use ($group) {
            $g = $groups->first(fn ($g) => $g->id === $group->id);

            return $g !== null
                && $g->users_count === 1
                && $g->permissions_count === 1;
        });
});
