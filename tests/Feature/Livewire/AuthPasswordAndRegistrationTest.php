<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Modules\Auth\Livewire\ForgotPassword;
use Modules\Auth\Livewire\Register;
use Modules\Auth\Livewire\ResetPassword;

it('renders the forgot password form', function () {
    Livewire::test(ForgotPassword::class)
        ->assertOk()
        ->assertSee('Forgot Password')
        ->assertSee('email');
});

it('sends a password reset link for a valid email', function () {
    Notification::fake();
    User::factory()->create(['email' => 'test@example.com']);

    Livewire::test(ForgotPassword::class)
        ->set('email', 'test@example.com')
        ->call('sendResetLink')
        ->assertDispatched('reset-link-sent');
});

it('shows validation error when email is missing on forgot password', function () {
    Livewire::test(ForgotPassword::class)
        ->set('email', '')
        ->call('sendResetLink')
        ->assertHasErrors(['email']);
});

it('shows validation error for invalid email format on forgot password', function () {
    Livewire::test(ForgotPassword::class)
        ->set('email', 'not-an-email')
        ->call('sendResetLink')
        ->assertHasErrors(['email']);
});

it('redirects /panel/register to login when registration is disabled', function () {
    config()->set('auth.registration_enabled', false);

    $this->get(route('panel.register'))
        ->assertRedirect(route('panel.login'));
});

it('aborts with 404 when component is mounted while registration is disabled', function () {
    config()->set('auth.registration_enabled', false);

    Livewire::test(Register::class)
        ->assertNotFound();
});

it('renders the register form', function () {
    config()->set('auth.registration_enabled', true);

    Livewire::test(Register::class)
        ->assertOk()
        ->assertSee('Create Account')
        ->assertSee('Minimum 8 characters')
        ->assertSee('Must be at least 8 characters.')
        ->assertSee('Must match password')
        ->assertSee('Re-enter password to confirm.');
});

it('registers a new user', function () {
    config()->set('auth.registration_enabled', true);

    Livewire::test(Register::class)
        ->set('name', 'Jane Doe')
        ->set('email', 'jane@example.com')
        ->set('password', 'secret123')
        ->set('password_confirmation', 'secret123')
        ->call('register')
        ->assertRedirect(route('panel.dashboard'));

    $this->assertDatabaseHas('users', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    $this->assertAuthenticated('web');
});

it('validates name and email are required on register', function () {
    config()->set('auth.registration_enabled', true);

    Livewire::test(Register::class)
        ->set('name', '')
        ->set('email', '')
        ->set('password', 'secret')
        ->set('password_confirmation', 'secret')
        ->call('register')
        ->assertHasErrors(['name', 'email']);
});

it('validates email is unique on register', function () {
    config()->set('auth.registration_enabled', true);
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 'taken@example.com')
        ->set('password', 'secret123')
        ->set('password_confirmation', 'secret123')
        ->call('register')
        ->assertHasErrors(['email']);
});

it('validates password confirmation matches on register', function () {
    config()->set('auth.registration_enabled', true);

    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 'test@example.com')
        ->set('password', 'secret123')
        ->set('password_confirmation', 'different')
        ->call('register')
        ->assertHasErrors(['password']);
});

it('renders the reset password form', function () {
    Notification::fake();
    $user = User::factory()->create();
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->assertOk()
        ->assertSee('Reset Password')
        ->assertSee('Minimum 8 characters')
        ->assertSee('Must be at least 8 characters.')
        ->assertSee('Must match password')
        ->assertSee('Re-enter password to confirm.');
});

it('resets the password with valid token', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => Hash::make('old-secret')]);
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->set('email', $user->email)
        ->set('password', 'new-secret-123')
        ->set('password_confirmation', 'new-secret-123')
        ->call('resetPassword')
        ->assertRedirect(route('panel.login'));

    $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
});

it('validates password is required on reset', function () {
    Notification::fake();
    $user = User::factory()->create();
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->set('password', '')
        ->call('resetPassword')
        ->assertHasErrors(['password']);
});

it('validates password confirmation matches on reset', function () {
    Notification::fake();
    $user = User::factory()->create();
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->set('email', $user->email)
        ->set('password', 'secret123')
        ->set('password_confirmation', 'different')
        ->call('resetPassword')
        ->assertHasErrors(['password']);
});

it('shows error for invalid token on reset', function () {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::test(ResetPassword::class, ['token' => 'invalid-token', 'email' => $user->email])
        ->set('email', $user->email)
        ->set('password', 'secret123')
        ->set('password_confirmation', 'secret123')
        ->call('resetPassword')
        ->assertHasErrors(['email']);
});
