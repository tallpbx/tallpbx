<?php

declare(strict_types=1);

use App\Models\Admin;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->admin = Admin::factory()->create([
        'email' => 'admin@example.com',
        'password' => bcrypt('password'),
    ]);

    grantAdminPermissions($this->admin, [
        'admin.dashboard.view',
        'admin.users.view',
        'admin.tenants.view',
        'admin.tenant-domains.view',
        'admin.groups.view',
        'admin.permissions.view',
        'admin.notifications.view',
        'extensions.view',
        'inbound-routes.view',
        'outbound-routes.view',
        'sip-profiles.view',
        'gateways.view',
        'ivr-menus.view',
        'devices.view',
        'access-controls.view',
        'feature-codes.view',
        'dialplans.view',
        'destinations.view',
        'voicemails.view',
        'sip-accounts.view',
    ]);
});

it('shows the unified login page', function () {
    get(route('panel.login'))
        ->assertOk()
        ->assertSee('TallPBX')
        ->assertSee('email')
        ->assertSee('password');
});

it('redirects authenticated admin away from login page', function () {
    actingAs($this->admin, 'admin')
        ->get(route('panel.login'))
        ->assertRedirect(route('panel.dashboard'));
});

it('authenticates an admin with valid credentials', function () {
    post(route('panel.login.store'), [
        'email' => 'admin@example.com',
        'password' => 'password',
    ])->assertRedirect(route('panel.dashboard'));

    $this->assertAuthenticatedAs($this->admin, 'admin');
});

it('authenticates a tenant user with valid credentials through the unified login', function () {
    $user = \App\Models\User::factory()->create([
        'email' => 'tenantuser@example.com',
        'password' => bcrypt('password'),
    ]);

    post(route('panel.login.store'), [
        'email' => 'tenantuser@example.com',
        'password' => 'password',
    ])->assertRedirect(route('panel.dashboard'));

    $this->assertAuthenticatedAs($user, 'web');
});

it('rejects invalid credentials', function () {
    post(route('panel.login.store'), [
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ])->assertInvalid('email');

    $this->assertGuest('admin');
});

it('rejects login for a disabled admin', function () {
    $this->admin->update(['enabled' => false]);

    post(route('panel.login.store'), [
        'email' => 'admin@example.com',
        'password' => 'password',
    ])->assertInvalid('email');

    $this->assertGuest('admin');
});

it('rate-limits login attempts after 10 tries', function () {
    $email = 'admin@example.com';

    for ($i = 0; $i < 10; $i++) {
        post(route('panel.login.store'), [
            'email' => $email,
            'password' => 'wrong',
        ])->assertInvalid('email');
    }

    post(route('panel.login.store'), [
        'email' => $email,
        'password' => 'wrong',
    ])->assertStatus(429);
});

it('logs out an admin', function () {
    actingAs($this->admin, 'admin')
        ->post(route('panel.logout'))
        ->assertRedirect('/');

    $this->assertGuest('admin');
});

it('redirects guest to admin login when accessing admin routes', function () {
    get(route('panel.dashboard'))
        ->assertRedirect(route('panel.login'));
});

it('allows authenticated admin to access dashboard', function () {
    actingAs($this->admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk();
});

it('allows authenticated admin to access admin pages', function () {
    $routes = [
        'panel.users.index', 'panel.extensions.index',
        'panel.tenants.index', 'panel.inbound-routes.index', 'panel.outbound-routes.index',
        'panel.sip-profiles.index', 'panel.gateways.index', 'panel.ivr-menus.index',
    ];

    actingAs($this->admin, 'admin');

    foreach ($routes as $route) {
        get(route($route))->assertOk();
    }
});
