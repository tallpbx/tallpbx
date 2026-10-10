<?php

use App\Models\Admin;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('redirects authenticated admin away from admin login', function () {
    $admin = Admin::factory()->create();

    actingAs($admin, 'admin')
        ->get(route('panel.login'))
        ->assertRedirect(route('panel.dashboard'));
});

it('redirects authenticated tenant user away from login', function () {
    $user = User::factory()->create();

    actingAs($user, 'web')
        ->get(route('panel.login'))
        ->assertRedirect(route('panel.dashboard'));
});

it('allows guest to view admin login page', function () {
    // Normal login behavior applies only after initial administrator setup.
    Admin::factory()->create();

    get(route('panel.login'))
        ->assertOk();
});
