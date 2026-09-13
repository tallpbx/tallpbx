<?php

use App\Livewire\Layout\DisplaySettings;
use App\Models\Admin;
use App\Models\User;
use Livewire\Livewire;

it('defaults to sidebar, expanded, and system theme for guests', function () {
    Livewire::test(DisplaySettings::class)
        ->assertSet('layoutMode', 'sidebar')
        ->assertSet('sidebarCollapsed', false)
        ->assertSet('theme', 'system');
});

it('loads preferences from authenticated admin', function () {
    $admin = Admin::factory()->create([
        'theme' => 'dark',
        'layout_mode' => 'horizontal',
        'sidebar_collapsed' => true,
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test(DisplaySettings::class)
        ->assertSet('theme', 'dark')
        ->assertSet('layoutMode', 'horizontal')
        ->assertSet('sidebarCollapsed', true);
});

it('loads preferences from authenticated user', function () {
    $user = User::factory()->create([
        'theme' => 'light',
        'layout_mode' => 'horizontal',
        'sidebar_collapsed' => false,
    ]);

    $this->actingAs($user);

    Livewire::test(DisplaySettings::class)
        ->assertSet('theme', 'light')
        ->assertSet('layoutMode', 'horizontal')
        ->assertSet('sidebarCollapsed', false);
});

it('updates layout mode for admin and dispatches event', function () {
    $admin = Admin::factory()->create(['layout_mode' => 'sidebar']);

    $this->actingAs($admin, 'admin');

    Livewire::test(DisplaySettings::class)
        ->call('setLayoutMode', 'horizontal')
        ->assertSet('layoutMode', 'horizontal')
        ->assertDispatched('layout-changed', mode: 'horizontal');

    expect($admin->fresh()->layout_mode)->toBe('horizontal');
});

it('updates layout mode for user and dispatches event', function () {
    $user = User::factory()->create(['layout_mode' => 'sidebar']);

    $this->actingAs($user);

    Livewire::test(DisplaySettings::class)
        ->call('setLayoutMode', 'horizontal')
        ->assertSet('layoutMode', 'horizontal')
        ->assertDispatched('layout-changed', mode: 'horizontal');

    expect($user->fresh()->layout_mode)->toBe('horizontal');
});

it('updates sidebar collapsed for admin and dispatches event', function () {
    $admin = Admin::factory()->create(['sidebar_collapsed' => false]);

    $this->actingAs($admin, 'admin');

    Livewire::test(DisplaySettings::class)
        ->call('setSidebarCollapsed', true)
        ->assertSet('sidebarCollapsed', true)
        ->assertDispatched('sidebar-collapse-changed', collapsed: true);

    expect($admin->fresh()->sidebar_collapsed)->toBeTrue();
});

it('updates sidebar collapsed for user and dispatches event', function () {
    $user = User::factory()->create(['sidebar_collapsed' => false]);

    $this->actingAs($user);

    Livewire::test(DisplaySettings::class)
        ->call('setSidebarCollapsed', true)
        ->assertSet('sidebarCollapsed', true)
        ->assertDispatched('sidebar-collapse-changed', collapsed: true);

    expect($user->fresh()->sidebar_collapsed)->toBeTrue();
});

it('updates theme and dispatches event', function () {
    $admin = Admin::factory()->create(['theme' => 'system']);

    $this->actingAs($admin, 'admin');

    Livewire::test(DisplaySettings::class)
        ->call('setTheme', 'dark')
        ->assertSet('theme', 'dark')
        ->assertDispatched('theme-changed', theme: 'dark');

    expect($admin->fresh()->theme)->toBe('dark');
});

it('renders layout elements and display settings on dashboard for authenticated admin', function () {
    $admin = Admin::factory()->create();
    grantAdminPermissions($admin, ['admin.dashboard.view']);

    $this->actingAs($admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSeeLivewire(DisplaySettings::class)
        ->assertSee('panel-sidebar');
});

