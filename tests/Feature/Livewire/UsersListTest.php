<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UserServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
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
