<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Modules\Admin\Livewire\AdminsEdit;
use Modules\Admin\Livewire\AdminsList;

beforeEach(function (): void {
    $this->seed(AdminSeeder::class);
    $this->admin = Admin::factory()->create([
        'name' => 'Root Admin',
        'email' => 'root@example.com',
        'enabled' => true,
    ]);

    $this->superGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin->groups()->attach($this->superGroup->id);
});

it('lists all administrators', function (): void {
    Admin::factory()->create([
        'name' => 'Second Admin',
        'email' => 'second@example.com',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsList::class)
        ->assertSee('Root Admin')
        ->assertSee('root@example.com')
        ->assertSee('Second Admin')
        ->assertSee('second@example.com');
});

it('prevents self-deletion of the authenticated admin', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsList::class)
        ->call('confirmAdminDeletion', $this->admin->id)
        ->assertSet('pendingDeletionId', null)
        ->assertSee(__('admin.admin_delete_self_error'));
});

it('prevents deleting the last super administrator', function (): void {
    $secondAdmin = Admin::factory()->create([
        'name' => 'Regular Admin',
        'email' => 'regular@example.com',
        'enabled' => true,
    ]);

    Livewire::actingAs($secondAdmin, 'admin')
        ->test(AdminsList::class)
        ->call('confirmAdminDeletion', $this->admin->id)
        ->assertSet('pendingDeletionId', null)
        ->assertSee(__('admin.admin_delete_last_super_error'));
});

it('deletes an administrator when allowed', function (): void {
    $otherSuperAdmin = Admin::factory()->create([
        'name' => 'Other Super Admin',
        'email' => 'other_super@example.com',
        'enabled' => true,
    ]);
    $superGroup = Group::where('name', 'Super Administrators')->first();
    $otherSuperAdmin->groups()->attach($superGroup->id);

    $targetAdmin = Admin::factory()->create([
        'name' => 'To Delete',
        'email' => 'delete_me@example.com',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsList::class)
        ->call('confirmAdminDeletion', $targetAdmin->id)
        ->assertSet('pendingDeletionId', $targetAdmin->id)
        ->call('deleteAdmin')
        ->assertDispatched('admin-deleted');

    expect(Admin::find($targetAdmin->id))->toBeNull();
});

it('mounts in create mode with default Super Administrators group', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsEdit::class)
        ->assertSet('adminId', null)
        ->assertSet('name', '')
        ->assertSet('email', '')
        ->assertSet('enabled', true)
        ->assertSet('selectedGroupIds', [$this->superGroup->id])
        ->assertSee('Minimum 8 characters')
        ->assertSee('Must be at least 8 characters.')
        ->assertSee('Must match password')
        ->assertSee('Re-enter password to confirm.');
});

it('creates a new administrator with assigned groups', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsEdit::class)
        ->set('name', 'Created Admin')
        ->set('email', 'created@example.com')
        ->set('password', 'SecretPassword123!')
        ->set('password_confirmation', 'SecretPassword123!')
        ->set('enabled', true)
        ->set('selectedGroupIds', [$this->superGroup->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('panel.admins.index'));

    $created = Admin::where('email', 'created@example.com')->first();

    expect($created)->not->toBeNull()
        ->and($created->name)->toBe('Created Admin')
        ->and(Hash::check('SecretPassword123!', $created->password))->toBeTrue()
        ->and($created->groups()->where('name', 'Super Administrators')->exists())->toBeTrue();
});

it('mounts in edit mode with existing admin data', function (): void {
    $targetAdmin = Admin::factory()->create([
        'name' => 'Target Admin',
        'email' => 'target@example.com',
        'enabled' => false,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsEdit::class, ['adminId' => $targetAdmin->id])
        ->assertSet('adminId', $targetAdmin->id)
        ->assertSet('name', 'Target Admin')
        ->assertSet('email', 'target@example.com')
        ->assertSet('enabled', false)
        ->assertSee('Leave blank to keep current password, or enter at least 8 characters to set a new one.')
        ->assertSee('Must match password')
        ->assertSee('Re-enter password to confirm.');
});

it('updates an existing administrator without requiring password', function (): void {
    $targetAdmin = Admin::factory()->create([
        'name' => 'Target Admin',
        'email' => 'target@example.com',
        'password' => Hash::make('UnchangedPassword123!'),
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsEdit::class, ['adminId' => $targetAdmin->id])
        ->set('name', 'Updated Target Name')
        ->set('email', 'new_target@example.com')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('panel.admins.index'));

    $targetAdmin->refresh();

    expect($targetAdmin->name)->toBe('Updated Target Name')
        ->and($targetAdmin->email)->toBe('new_target@example.com')
        ->and(Hash::check('UnchangedPassword123!', $targetAdmin->password))->toBeTrue();
});

it('updates password when provided on edit', function (): void {
    $targetAdmin = Admin::factory()->create([
        'name' => 'Target Admin',
        'email' => 'target@example.com',
        'password' => Hash::make('OldPassword123!'),
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsEdit::class, ['adminId' => $targetAdmin->id])
        ->set('password', 'NewSecret123!')
        ->set('password_confirmation', 'NewSecret123!')
        ->call('save')
        ->assertHasNoErrors();

    $targetAdmin->refresh();

    expect(Hash::check('NewSecret123!', $targetAdmin->password))->toBeTrue();
});

it('prevents administrator from disabling their own account', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsEdit::class, ['adminId' => $this->admin->id])
        ->set('enabled', false)
        ->call('save')
        ->assertHasErrors(['enabled']);

    $this->admin->refresh();

    expect($this->admin->enabled)->toBeTrue();
});
