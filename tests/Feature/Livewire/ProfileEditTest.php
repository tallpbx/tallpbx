<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Modules\Admin\Livewire\ProfileEdit;

beforeEach(function (): void {
    $this->seed(AdminSeeder::class);
    $this->admin = Admin::factory()->create([
        'name' => 'Original Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('CurrentPassword123!'),
        'enabled' => true,
    ]);
});

it('mounts with the authenticated admin details', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ProfileEdit::class)
        ->assertSet('name', 'Original Admin')
        ->assertSet('email', 'admin@example.com')
        ->assertSee('My Profile')
        ->assertSee('Profile Information')
        ->assertSee('Change Password')
        ->assertSee('Save')
        ->assertDontSee('admin.save');
});

it('updates admin name and email successfully', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ProfileEdit::class)
        ->set('name', 'New Admin Name')
        ->set('email', 'updated@example.com')
        ->call('updateProfile')
        ->assertHasNoErrors()
        ->assertSet('profileSuccess', __('admin.profile_updated'));

    $this->admin->refresh();

    expect($this->admin->name)->toBe('New Admin Name')
        ->and($this->admin->email)->toBe('updated@example.com');
});

it('validates unique email on profile update', function (): void {
    Admin::factory()->create(['email' => 'taken@example.com']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ProfileEdit::class)
        ->set('email', 'taken@example.com')
        ->call('updateProfile')
        ->assertHasErrors(['email' => 'unique']);
});

it('updates password when current password is valid', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ProfileEdit::class)
        ->set('currentPassword', 'CurrentPassword123!')
        ->set('newPassword', 'BrandNewPassword123!')
        ->set('newPassword_confirmation', 'BrandNewPassword123!')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertSet('passwordSuccess', __('admin.password_updated'));

    $this->admin->refresh();

    expect(Hash::check('BrandNewPassword123!', $this->admin->password))->toBeTrue();
});

it('rejects password update when current password is incorrect', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ProfileEdit::class)
        ->set('currentPassword', 'WrongPassword123!')
        ->set('newPassword', 'BrandNewPassword123!')
        ->set('newPassword_confirmation', 'BrandNewPassword123!')
        ->call('updatePassword')
        ->assertHasErrors(['currentPassword']);

    $this->admin->refresh();

    expect(Hash::check('CurrentPassword123!', $this->admin->password))->toBeTrue();
});

it('also works for web guard tenant users', function (): void {
    $user = User::factory()->create([
        'name' => 'Tenant User',
        'email' => 'tenant@example.com',
        'password' => Hash::make('TenantSecret123!'),
    ]);

    Livewire::actingAs($user, 'web')
        ->test(ProfileEdit::class)
        ->assertSet('name', 'Tenant User')
        ->assertSet('email', 'tenant@example.com')
        ->set('name', 'Updated Tenant')
        ->call('updateProfile')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Tenant');
});
