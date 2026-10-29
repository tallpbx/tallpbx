<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GroupServiceInterface;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Admin\Livewire\GroupsEdit;
use Modules\Admin\Livewire\GroupsList;

beforeEach(function () {
    $this->admin = grantAdminPermissions(Admin::factory()->create(['enabled' => true]), [
        'panel.dashboard.view',
        'admin.groups.view',
        'admin.groups.create',
        'admin.groups.update',
        'admin.groups.delete',
    ]);
    $permService = app(PermissionService::class);
    $permService->register('test-module', ['test.view', 'test.create', 'test.edit', 'test.delete']);
    $permService->syncToDatabase();
});

it('renders the groups list component', function () {
    $initialCount = Group::count();
    Group::factory()->system()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class)
        ->assertOk()
        ->assertSee('Groups')
        ->assertViewHas('groups', function ($groups) use ($initialCount) {
            return $groups->count() === $initialCount + 3;
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
    $initialSystemCount = Group::whereNull('tenant_id')->count();
    Group::factory()->system()->count(1)->create();
    Group::factory()->forTenant($tenant->id)->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsList::class)
        ->assertViewHas('groups', function ($groups) use ($initialSystemCount) {
            return $groups->count() === $initialSystemCount + 1;
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

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class)
        ->assertOk()
        ->assertSee('Create Group')
        ->assertSet('name', '')
        ->assertSet('description', '');
});

it('renders the edit form with existing group data', function () {
    $group = Group::factory()->system()->create([
        'name' => 'Super Admins',
        'description' => 'Full access group',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class, ['group' => $group->id])
        ->assertOk()
        ->assertSee('Edit Group')
        ->assertSet('name', 'Super Admins')
        ->assertSet('description', 'Full access group');
});

it('creates a new system group', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class)
        ->set('name', 'New Group')
        ->set('description', 'A test group')
        ->call('save')
        ->assertRedirect(route('panel.groups.index'));

    $this->assertDatabaseHas('groups', [
        'name' => 'New Group',
        'description' => 'A test group',
        'tenant_id' => null,
    ]);
});

it('creates a tenant-scoped group', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class)
        ->set('name', 'Tenant Group')
        ->set('tenantId', $tenant->id)
        ->call('save')
        ->assertRedirect(route('panel.groups.index'));

    $this->assertDatabaseHas('groups', [
        'name' => 'Tenant Group',
        'tenant_id' => $tenant->id,
    ]);
});

it('updates an existing group', function () {
    $group = Group::factory()->system()->create([
        'name' => 'Old Name',
        'description' => 'Old description',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class, ['group' => $group->id])
        ->set('name', 'Updated Name')
        ->set('description', 'Updated description')
        ->call('save')
        ->assertRedirect(route('panel.groups.index'));

    $this->assertDatabaseHas('groups', [
        'id' => $group->id,
        'name' => 'Updated Name',
        'description' => 'Updated description',
    ]);
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates name is unique across system groups', function () {
    Group::factory()->system()->create(['name' => 'Unique Group']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class)
        ->set('name', 'Unique Group')
        ->call('save')
        ->assertHasErrors(['name']);
});

it('allows same name for different tenants', function () {
    $tenant = Tenant::factory()->create();
    Group::factory()->system()->create(['name' => 'Shared Name']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class)
        ->set('name', 'Shared Name')
        ->set('tenantId', $tenant->id)
        ->call('save')
        ->assertRedirect(route('panel.groups.index'));
});

it('creates a group with selected permissions', function () {
    $permIds = Permission::whereIn('name', ['test.view', 'test.create'])
        ->pluck('id')
        ->all();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class)
        ->set('name', 'Group With Perms')
        ->set('selectedPermissions', $permIds)
        ->call('save')
        ->assertRedirect(route('panel.groups.index'));

    $group = Group::where('name', 'Group With Perms')->first();
    expect($group)->not->toBeNull();
    expect($group->permissions()->pluck('name')->all())
        ->toContain('test.view')
        ->toContain('test.create')
        ->not->toContain('test.edit');
});

it('updates a group with new permissions', function () {
    $group = Group::factory()->system()->create(['name' => 'Update Perms']);
    $permIds = Permission::whereIn('name', ['test.edit', 'test.delete'])
        ->pluck('id')
        ->all();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class, ['group' => $group->id])
        ->set('selectedPermissions', $permIds)
        ->call('save')
        ->assertRedirect(route('panel.groups.index'));

    expect($group->fresh()->permissions()->pluck('name')->all())
        ->toContain('test.edit')
        ->toContain('test.delete')
        ->not->toContain('test.view');
});

it('can clear all permissions from a group', function () {
    $perm = Permission::where('name', 'test.view')->first();
    $group = Group::factory()->system()->create(['name' => 'Clear Perms']);
    $group->permissions()->attach($perm);

    expect($group->permissions()->count())->toBe(1);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class, ['group' => $group->id])
        ->set('selectedPermissions', [])
        ->call('save')
        ->assertRedirect(route('panel.groups.index'));

    expect($group->fresh()->permissions()->count())->toBe(0);
});

it('shows permission groups on the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class)
        ->assertOk()
        ->assertSee('Permissions')
        ->assertSee('test-module')
        ->assertSee('test.view')
        ->assertSee('test.create');
});

it('loads existing permissions in edit mode', function () {
    $perm = Permission::where('name', 'test.view')->first();
    $group = Group::factory()->system()->create(['name' => 'Loaded Perms']);
    $group->permissions()->attach($perm);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class, ['group' => $group->id])
        ->assertOk()
        ->assertSet('selectedPermissions', [$perm->id]);
});

it('hides disabled module permissions while preserving existing assignments', function () {
    Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
        'enabled' => false,
    ]);

    $disabledPermission = Permission::firstOrCreate(
        ['name' => 'extensions.view'],
        ['module' => 'extensions', 'description' => 'View extensions'],
    );

    $visiblePermission = Permission::where('name', 'test.view')->firstOrFail();
    $group = Group::factory()->system()->create(['name' => 'Preserve Disabled Permissions']);
    $group->permissions()->attach([$disabledPermission->id, $visiblePermission->id]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GroupsEdit::class, ['group' => $group->id])
        ->assertOk()
        ->assertDontSee('extensions.view')
        ->assertSet('selectedPermissions', [$disabledPermission->id, $visiblePermission->id])
        ->call('save')
        ->assertRedirect(route('panel.groups.index'));

    expect($group->fresh()->permissions()->pluck('name')->all())
        ->toContain('extensions.view')
        ->toContain('test.view');
});
