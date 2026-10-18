<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UserServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Admin\Livewire\UsersEdit;
use Modules\Admin\Livewire\UsersList;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the users list component', function () {
    User::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersList::class)
        ->assertOk()
        ->assertSee('Users')
        ->assertViewHas('users', function ($users) {
            return $users->count() === 3;
        });
});

it('deletes a user after modal confirmation', function () {
    $user = User::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersList::class)
        ->call('confirmUserDeletion', $user->id)
        ->assertSet('pendingDeletionId', $user->id)
        ->call('deleteUser')
        ->assertSet('operationalMessage', 'User deleted.')
        ->assertDispatched('user-deleted');

    $this->assertModelMissing($user);
});

it('opens the shared confirmation modal before deleting a user', function (): void {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersList::class)
        ->call('confirmUserDeletion', $user->id)
        ->assertSet('pendingDeletionId', $user->id)
        ->assertSet('pendingDeletionName', 'jane@example.com')
        ->assertSee('Delete User?');
});

it('keeps the modal open with a safe error when user deletion fails', function (): void {
    $user = User::factory()->create();

    $service = Mockery::mock(UserServiceInterface::class);
    $service->shouldReceive('all')->andReturn(new Collection([$user]));
    $service->shouldReceive('delete')->once()->andThrow(new RuntimeException('user is the last admin'));
    app()->instance(UserServiceInterface::class, $service);

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersList::class)
        ->call('confirmUserDeletion', $user->id)
        ->call('deleteUser')
        ->assertSet('deleteError', 'User could not be deleted. user is the last admin');

    $this->assertModelExists($user);
});

it('shows user tenant and group counts', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $group = Group::factory()->system()->create();

    $user->tenants()->attach($tenant, ['role' => 'member']);
    $user->groups()->attach($group);

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersList::class)
        ->assertViewHas('users', function ($users) use ($user) {
            $u = $users->first(fn ($u) => $u->id === $user->id);

            return $u !== null
                && $u->tenants_count === 1
                && $u->groups_count === 1;
        });
});

it('renders the create form', function () {
    $tenant = Tenant::factory()->create(['name' => 'Acme Tenant']);
    $group = Group::factory()->system()->create(['name' => 'Operators']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersEdit::class)
        ->assertOk()
        ->assertSee('Create User')
        ->assertSee('Search tenants')
        ->assertSee('Select tenants')
        ->assertSee('Acme Tenant')
        ->assertSee('Operators')
        ->assertSet('name', '')
        ->assertSet('email', '')
        ->assertSet('selectedTenantIds', [])
        ->assertSet('selectedGroupIds', [])
        ->assertSee('Minimum 8 characters')
        ->assertSee('Must be at least 8 characters.');
});

it('renders the edit form with existing user data', function () {
    $tenant = Tenant::factory()->create();
    $group = Group::factory()->system()->create();
    $user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
    $user->tenants()->attach($tenant, ['role' => 'member', 'primary' => true]);
    $user->groups()->attach($group);

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersEdit::class, ['userId' => $user->id])
        ->assertOk()
        ->assertSee('Edit User')
        ->assertSet('name', 'John Doe')
        ->assertSet('email', 'john@example.com')
        ->assertSet('selectedTenantIds', [$tenant->id])
        ->assertSet('selectedGroupIds', [$group->id])
        ->assertSee('Leave blank to keep current password, or enter at least 8 characters to set a new one.');
});

it('renders the edit form when visited through the panel route', function () {
    $admin = grantAdminPermissions($this->admin, ['admin.users.update']);
    $user = User::factory()->create([
        'name' => 'Route Loaded User',
        'email' => 'route-loaded@example.com',
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('panel.users.edit', $user->id))
        ->assertOk()
        ->assertSee('Edit User')
        ->assertSee('Route Loaded User')
        ->assertSee('route-loaded@example.com');
});

it('creates a new user', function () {
    $tenant = Tenant::factory()->create();
    $group = Group::factory()->system()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersEdit::class)
        ->set('name', 'New User')
        ->set('email', 'newuser@example.com')
        ->set('password', 'secret123')
        ->set('selectedTenantIds', [$tenant->id])
        ->set('selectedGroupIds', [$group->id])
        ->call('save')
        ->assertRedirect(route('panel.users.index'));

    $this->assertDatabaseHas('users', [
        'name' => 'New User',
        'email' => 'newuser@example.com',
    ]);

    $user = User::where('email', 'newuser@example.com')->firstOrFail();

    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'role' => 'member',
        'primary' => true,
    ]);
    $this->assertDatabaseHas('group_user', [
        'group_id' => $group->id,
        'user_id' => $user->id,
    ]);
});

it('updates an existing user', function () {
    $oldTenant = Tenant::factory()->create();
    $newTenant = Tenant::factory()->create();
    $oldGroup = Group::factory()->system()->create();
    $newGroup = Group::factory()->system()->create();
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);
    $user->tenants()->attach($oldTenant, ['role' => 'member', 'primary' => true]);
    $user->groups()->attach($oldGroup);

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersEdit::class, ['userId' => $user->id])
        ->set('name', 'Updated Name')
        ->set('email', 'updated@example.com')
        ->set('selectedTenantIds', [$newTenant->id])
        ->set('selectedGroupIds', [$newGroup->id])
        ->call('save')
        ->assertRedirect(route('panel.users.index'));

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);
    $this->assertDatabaseMissing('tenant_user', [
        'tenant_id' => $oldTenant->id,
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $newTenant->id,
        'user_id' => $user->id,
        'role' => 'member',
        'primary' => true,
    ]);
    $this->assertDatabaseMissing('group_user', [
        'group_id' => $oldGroup->id,
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('group_user', [
        'group_id' => $newGroup->id,
        'user_id' => $user->id,
    ]);
});

it('validates name and email are required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersEdit::class)
        ->set('name', '')
        ->set('email', '')
        ->call('save')
        ->assertHasErrors(['name', 'email']);
});

it('validates email is unique', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(UsersEdit::class)
        ->set('name', 'Test')
        ->set('email', 'taken@example.com')
        ->set('password', 'secret')
        ->call('save')
        ->assertHasErrors(['email']);
});
