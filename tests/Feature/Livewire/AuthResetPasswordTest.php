<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Modules\Auth\Livewire\ResetPassword;

beforeEach(function () {
    Notification::fake();
});

it('renders the reset password form', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->assertOk()
        ->assertSee('Reset Password')
        ->assertSee('email')
        ->assertSee('password');
});

it('resets the password with valid token', function () {
    $user = User::factory()->create(['password' => Hash::make('old-secret')]);
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->set('email', $user->email)
        ->set('password', 'new-secret-123')
        ->set('password_confirmation', 'new-secret-123')
        ->call('resetPassword')
        ->assertRedirect(route('panel.login.tenant'));

    // Verify the password was actually changed
    $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
});

it('validates password is required', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->set('password', '')
        ->call('resetPassword')
        ->assertHasErrors(['password']);
});

it('validates password confirmation matches', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->set('email', $user->email)
        ->set('password', 'secret123')
        ->set('password_confirmation', 'different')
        ->call('resetPassword')
        ->assertHasErrors(['password']);
});

it('shows error for invalid token', function () {
    $user = User::factory()->create();

    Livewire::test(ResetPassword::class, ['token' => 'invalid-token', 'email' => $user->email])
        ->set('email', $user->email)
        ->set('password', 'secret123')
        ->set('password_confirmation', 'secret123')
        ->call('resetPassword')
        ->assertHasErrors(['email']);
});
