<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Auth\Livewire\ForgotPassword;

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

it('shows validation error when email is missing', function () {
    Livewire::test(ForgotPassword::class)
        ->set('email', '')
        ->call('sendResetLink')
        ->assertHasErrors(['email']);
});

it('shows validation error for invalid email format', function () {
    Livewire::test(ForgotPassword::class)
        ->set('email', 'not-an-email')
        ->call('sendResetLink')
        ->assertHasErrors(['email']);
});
