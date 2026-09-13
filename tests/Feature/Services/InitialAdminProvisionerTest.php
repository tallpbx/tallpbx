<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Setting;
use App\Services\InitialAdminProvisioner;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(AdminSeeder::class);
});

it('creates the first administrator through the installer mode', function (): void {
    $provisioner = app(InitialAdminProvisioner::class);

    $admin = $provisioner->provisionFromInstaller(
        email: 'admin@example.test',
        password: 'correct-horse-battery-staple',
    );

    expect($admin)->toBeInstanceOf(Admin::class)
        ->and($admin->email)->toBe('admin@example.test')
        ->and(Hash::check('correct-horse-battery-staple', $admin->password))->toBeTrue()
        ->and($admin->groups()->where('name', 'Super Administrators')->exists())->toBeTrue()
        ->and(Setting::system()->where('key', 'initial_admin.setup')->value('value'))->toContain('completed');
});

it('requires and consumes a one-time activation code', function (): void {
    $provisioner = app(InitialAdminProvisioner::class);
    $activationCode = $provisioner->configureBrowserSetup(InitialAdminProvisioner::MODE_ACTIVATION_CODE);

    expect($activationCode)->toBeString()
        ->and($provisioner->configureBrowserSetup(InitialAdminProvisioner::MODE_ACTIVATION_CODE))->toBeNull();

    expect(fn () => $provisioner->provisionFromBrowser(
        email: 'admin@example.test',
        password: 'correct-horse-battery-staple',
        activationCode: 'wrong-code',
    ))->toThrow('The activation code is invalid.');

    $admin = $provisioner->provisionFromBrowser(
        email: 'admin@example.test',
        password: 'correct-horse-battery-staple',
        activationCode: $activationCode,
    );

    expect($admin->email)->toBe('admin@example.test')
        ->and($provisioner->browserSetupMode())->toBeNull()
        ->and(Setting::system()->where('key', 'initial_admin.setup')->value('value'))->not->toContain('activation_code_hash');
});
