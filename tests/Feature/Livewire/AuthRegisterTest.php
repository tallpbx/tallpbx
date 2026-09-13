<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Auth\Livewire\Register;

it('renders the register form', function () {
    Livewire::test(Register::class)
        ->assertOk()
        ->assertSee('Create Account')
        ->assertSee('name')
        ->assertSee('email')
        ->assertSee('password');
});

it('registers a new user', function () {
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

it('validates name and email are required', function () {
    Livewire::test(Register::class)
        ->set('name', '')
        ->set('email', '')
        ->set('password', 'secret')
        ->set('password_confirmation', 'secret')
        ->call('register')
        ->assertHasErrors(['name', 'email']);
});

it('validates email is unique', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 'taken@example.com')
        ->set('password', 'secret123')
        ->set('password_confirmation', 'secret123')
        ->call('register')
        ->assertHasErrors(['email']);
});

it('validates password confirmation matches', function () {
    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 'test@example.com')
        ->set('password', 'secret123')
        ->set('password_confirmation', 'different')
        ->call('register')
        ->assertHasErrors(['password']);
});
