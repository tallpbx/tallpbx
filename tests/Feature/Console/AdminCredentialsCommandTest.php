<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Setting;
use App\Services\InitialAdminProvisioner;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(AdminSeeder::class);
});

it('prepares a browser activation code without creating an administrator', function (): void {
    $exitCode = Artisan::call('initial-admin:configure', [
        'mode' => InitialAdminProvisioner::MODE_ACTIVATION_CODE,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('One-time administrator activation code:')
        ->and(Setting::system()->where('key', 'initial_admin.setup')->value('value'))->toContain('activation-code');
});

it('updates password and name for an existing administrator non-interactively', function (): void {
    /** @var Admin $admin */
    $admin = Admin::factory()->create([
        'email' => 'admin@example.com',
        'name' => 'Old Name',
        'password' => Hash::make('OldPassword123!'),
        'enabled' => false,
    ]);

    $this->artisan('admin:credentials', [
        'email' => 'admin@example.com',
        '--name' => 'Updated Name',
        '--password' => 'NewPassword123!',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Administrator [admin@example.com] credentials updated successfully.');

    $admin->refresh();

    expect($admin->name)->toBe('Updated Name')
        ->and($admin->enabled)->toBeTrue()
        ->and(Hash::check('NewPassword123!', $admin->password))->toBeTrue()
        ->and($admin->groups()->where('name', 'Super Administrators')->exists())->toBeTrue();
});

it('creates a new administrator non-interactively when not found', function (): void {
    $this->artisan('admin:credentials', [
        'email' => 'newadmin@example.com',
        '--name' => 'Super User',
        '--password' => 'SecretPassword123!',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('New administrator [Super User <newadmin@example.com>] created successfully with Super Administrator privileges.');

    $admin = Admin::where('email', 'newadmin@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->name)->toBe('Super User')
        ->and($admin->enabled)->toBeTrue()
        ->and(Hash::check('SecretPassword123!', $admin->password))->toBeTrue()
        ->and($admin->groups()->where('name', 'Super Administrators')->exists())->toBeTrue();
});

it('fails with invalid email format', function (): void {
    $this->artisan('admin:credentials', [
        'email' => 'invalid-email',
        '--password' => 'SecretPassword123!',
    ])
        ->assertFailed()
        ->expectsOutputToContain('A valid email address is required.');
});

it('fails when password is too short', function (): void {
    $this->artisan('admin:credentials', [
        'email' => 'short@example.com',
        '--password' => 'short',
    ])
        ->assertFailed()
        ->expectsOutputToContain('The password must be at least 8 characters.');
});

it('marks initial admin setup setting as completed', function (): void {
    Setting::system()->updateOrCreate(
        ['key' => 'initial_admin.setup'],
        [
            'value' => json_encode(['status' => 'pending', 'mode' => 'installer', 'activation_code_hash' => null]),
            'type' => 'json',
        ],
    );

    $this->artisan('admin:credentials', [
        'email' => 'setup@example.com',
        '--name' => 'Setup Admin',
        '--password' => 'SecretPassword123!',
    ])->assertSuccessful();

    $setting = Setting::system()->where('key', 'initial_admin.setup')->first();
    $decoded = json_decode($setting->value, true);

    expect($decoded['status'])->toBe('completed');
});

it('supports command aliases admin:password and admin:user', function (): void {
    $this->artisan('admin:password', [
        'email' => 'alias1@example.com',
        '--name' => 'Alias One',
        '--password' => 'SecretPassword123!',
    ])->assertSuccessful();

    expect(Admin::where('email', 'alias1@example.com')->exists())->toBeTrue();

    $this->artisan('admin:user', [
        'email' => 'alias2@example.com',
        '--name' => 'Alias Two',
        '--password' => 'SecretPassword123!',
    ])->assertSuccessful();

    expect(Admin::where('email', 'alias2@example.com')->exists())->toBeTrue();
});
