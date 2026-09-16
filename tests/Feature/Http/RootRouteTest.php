<?php

use App\Models\Admin;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('redirects guests to localized home page', function () {
    get('/')
        ->assertRedirect('/en');
});

it('shows landing page for guests with locale prefix', function () {
    get('/en')
        ->assertOk()
        ->assertSee('TallPBX')
        ->assertSee('FreeSWITCH Telephone Platform')
        ->assertSee('TALL stands for Tailwind CSS, Alpine.js, Laravel, and Livewire, otherwise known as the TALL stack')
        ->assertSee('modular architecture keeps each installation focused')
        ->assertSee('third-party developers')
        ->assertSee('trusted repositories')
        ->assertSee('Sign In');
});

it('redirects authenticated admin to admin dashboard', function () {
    $admin = Admin::factory()->create();

    actingAs($admin, 'admin')
        ->get('/')
        ->assertRedirect(route('panel.dashboard'));
});

it('redirects authenticated user to portal dashboard', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/')
        ->assertRedirect(route('panel.dashboard'));
});

it('fallback redirects guest to root', function () {
    get('/nonexistent-page')
        ->assertRedirect('/');
});

it('fallback redirects admin to admin dashboard', function () {
    $admin = Admin::factory()->create();

    actingAs($admin, 'admin')
        ->get('/nonexistent-page')
        ->assertRedirect(route('panel.dashboard'));
});

it('fallback redirects user to portal dashboard', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/nonexistent-page')
        ->assertRedirect(route('panel.dashboard'));
});
