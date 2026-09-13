<?php

use App\Livewire\Layout\ThemeSwitcher;
use App\Models\Admin;
use App\Models\User;
use Livewire\Livewire;

it('defaults to system theme for guests', function () {
    Livewire::test(ThemeSwitcher::class)
        ->assertSet('theme', 'system');
});

it('loads theme from authenticated admin', function () {
    $admin = Admin::factory()->create(['theme' => 'dark']);

    $this->actingAs($admin, 'admin');

    Livewire::test(ThemeSwitcher::class)
        ->assertSet('theme', 'dark');
});

it('loads theme from authenticated user', function () {
    $user = User::factory()->create(['theme' => 'light']);

    $this->actingAs($user);

    Livewire::test(ThemeSwitcher::class)
        ->assertSet('theme', 'light');
});

it('updates theme for admin and dispatches event', function () {
    $admin = Admin::factory()->create(['theme' => 'system']);

    $this->actingAs($admin, 'admin');

    Livewire::test(ThemeSwitcher::class)
        ->call('setTheme', 'dark')
        ->assertSet('theme', 'dark')
        ->assertDispatched('theme-changed', theme: 'dark');

    expect($admin->fresh()->theme)->toBe('dark');
});

it('updates theme for user and dispatches event', function () {
    $user = User::factory()->create(['theme' => 'system']);

    $this->actingAs($user);

    Livewire::test(ThemeSwitcher::class)
        ->call('setTheme', 'light')
        ->assertSet('theme', 'light')
        ->assertDispatched('theme-changed', theme: 'light');

    expect($user->fresh()->theme)->toBe('light');
});

it('persists theme selection across calls', function () {
    $admin = Admin::factory()->create(['theme' => 'system']);

    $this->actingAs($admin, 'admin');

    Livewire::test(ThemeSwitcher::class)
        ->call('setTheme', 'dark')
        ->call('setTheme', 'light')
        ->call('setTheme', 'system')
        ->assertSet('theme', 'system');

    expect($admin->fresh()->theme)->toBe('system');
});
