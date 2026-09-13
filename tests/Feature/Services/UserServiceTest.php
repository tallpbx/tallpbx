<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UserServiceInterface;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->service = app(UserServiceInterface::class);
});

it('creates a user', function () {
    $user = $this->service->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'secret123',
    ]);

    expect($user)
        ->toBeInstanceOf(User::class)
        ->name->toBe('John Doe')
        ->email->toBe('john@example.com');

    expect(Hash::check('secret123', $user->password))->toBeTrue();
});

it('updates a user', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    $updated = $this->service->update($user, [
        'name' => 'New Name',
        'email' => 'new@example.com',
    ]);

    expect($updated->name)->toBe('New Name')
        ->and($updated->email)->toBe('new@example.com');
});

it('updates a user password when provided', function () {
    $user = User::factory()->create(['password' => Hash::make('old')]);

    $this->service->update($user, [
        'name' => $user->name,
        'password' => 'newpassword',
    ]);

    expect(Hash::check('newpassword', $user->fresh()->password))->toBeTrue();
});

it('creates a user with tenant and group assignments atomically', function () {
    $tenant = Tenant::factory()->create();
    $group = Group::factory()->system()->create();

    $user = $this->service->saveWithAssignments(
        null,
        [
            'name' => 'Assigned User',
            'email' => 'assigned@example.com',
            'password' => 'secret123',
        ],
        [$tenant->id],
        [$group->id],
    );

    expect($user->email)->toBe('assigned@example.com')
        ->and($user->tenants)->toHaveCount(1)
        ->and($user->groups)->toHaveCount(1)
        ->and($user->tenants->first()->pivot->role)->toBe('member')
        ->and((bool) $user->tenants->first()->pivot->primary)->toBeTrue()
        ->and($user->groups->first()->id)->toBe($group->id);
});

it('deletes a user', function () {
    $user = User::factory()->create();

    $this->service->delete($user);

    $this->assertModelMissing($user);
});

it('deletes a user with tenant relationships', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($user, ['role' => 'admin']);

    $this->service->delete($user);

    $this->assertModelMissing($user);
    expect($tenant->users()->count())->toBe(0);
});

it('assigns a tenant to a user', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    $this->service->assignTenant($user, $tenant, 'member');

    expect($user->tenants()->count())->toBe(1)
        ->and($user->tenants()->first()->id)->toBe($tenant->id);
});

it('removes a tenant from a user', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'member']);

    $this->service->removeTenant($user, $tenant);

    expect($user->tenants()->count())->toBe(0);
});

it('adds a user to a group', function () {
    $user = User::factory()->create();
    $group = Group::factory()->system()->create();

    $this->service->addToGroup($user, $group);

    expect($user->groups()->count())->toBe(1)
        ->and($user->groups()->first()->id)->toBe($group->id);
});

it('removes a user from a group', function () {
    $user = User::factory()->create();
    $group = Group::factory()->system()->create();
    $user->groups()->attach($group);

    $this->service->removeFromGroup($user, $group);

    expect($user->groups()->count())->toBe(0);
});

it('lists all users', function () {
    User::factory()->count(3)->create();

    $users = $this->service->all();

    expect($users)->toHaveCount(3);
});

it('finds a user by email', function () {
    $user = User::factory()->create(['email' => 'findme@example.com']);

    $found = $this->service->findByEmail('findme@example.com');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($user->id);
});
