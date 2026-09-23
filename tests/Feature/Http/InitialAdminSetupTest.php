<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Services\InitialAdminProvisioner;
use Database\Seeders\AdminSeeder;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function (): void {
    $this->seed(AdminSeeder::class);
});

it('lets a trusted-network installation create its first administrator in the browser', function (): void {
    get(route('panel.initial-admin.setup'))
        ->assertOk()
        ->assertSee('Create the first administrator')
        ->assertSee('Minimum 8 characters')
        ->assertSee('Must be at least 8 characters.')
        ->assertSee('Must match password')
        ->assertSee('Re-enter password to confirm.');

    post(route('panel.initial-admin.store'), [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect(route('panel.dashboard'));

    expect(Admin::query()->where('email', 'admin@example.test')->exists())->toBeTrue();
    $this->assertAuthenticated('admin');
});

it('requires a valid activation code when that browser mode was selected', function (): void {
    $activationCode = app(InitialAdminProvisioner::class)
        ->configureBrowserSetup(InitialAdminProvisioner::MODE_ACTIVATION_CODE);

    post(route('panel.initial-admin.store'), [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'activation_code' => 'wrong-code',
    ])->assertInvalid('activation_code');

    post(route('panel.initial-admin.store'), [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'activation_code' => $activationCode,
    ])->assertRedirect(route('panel.dashboard'));

    expect(Admin::query()->where('email', 'admin@example.test')->exists())->toBeTrue();
});

it('redirects the administrator login page to setup while an activation code is pending', function (): void {
    app(InitialAdminProvisioner::class)
        ->configureBrowserSetup(InitialAdminProvisioner::MODE_ACTIVATION_CODE);

    get(route('panel.login'))
        ->assertRedirect(route('panel.initial-admin.setup'));
});

it('redirects the administrator login page to setup while trusted-network setup is pending', function (): void {
    app(InitialAdminProvisioner::class)
        ->configureBrowserSetup(InitialAdminProvisioner::MODE_TRUSTED_NETWORK);

    get(route('panel.login'))
        ->assertRedirect(route('panel.initial-admin.setup'));
});

it('identifies the email as the account name before the activation code for password managers', function (): void {
    app(InitialAdminProvisioner::class)
        ->configureBrowserSetup(InitialAdminProvisioner::MODE_ACTIVATION_CODE);

    get(route('panel.initial-admin.setup'))
        ->assertSee('name="email" id="email" value="" required autofocus autocomplete="username"', false)
        ->assertSee('name="activation_code" id="activation_code" required autocomplete="one-time-code"', false)
        ->assertSeeInOrder([
            'name="email"',
            'name="activation_code"',
            'name="password"',
        ], false);
});
