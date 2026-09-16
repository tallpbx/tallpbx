<?php

use App\Models\Tenant;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'user@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->tenant = Tenant::factory()->create();
    $this->tenant->users()->attach($this->user, ['role' => 'admin']);
});

it('shows the tenant login page via unified login', function () {
    get(route('panel.login.tenant'))
        ->assertOk()
        ->assertSee('Sign in to your account')
        ->assertSee('email')
        ->assertSee('password');
});

it('redirects authenticated user away from login page', function () {
    actingAs($this->user)
        ->get(route('panel.login.tenant'))
        ->assertRedirect(route('panel.dashboard'));
});

it('authenticates a user with valid credentials', function () {
    post(route('panel.login.tenant.store'), [
        'email' => 'user@example.com',
        'password' => 'password',
    ])->assertRedirect(route('panel.dashboard'));

    $this->assertAuthenticatedAs($this->user, 'web');
});

it('rejects invalid credentials', function () {
    post(route('panel.login.tenant.store'), [
        'email' => 'user@example.com',
        'password' => 'wrong-password',
    ])->assertInvalid('email');

    $this->assertGuest('web');
});

it('rejects login for a disabled user', function () {
    $this->user->update(['enabled' => false]);

    post(route('panel.login.tenant.store'), [
        'email' => 'user@example.com',
        'password' => 'password',
    ])->assertInvalid('email');

    $this->assertGuest('web');
});

it('rate-limits login attempts after 10 tries', function () {
    $email = 'user@example.com';

    for ($i = 0; $i < 10; $i++) {
        post(route('panel.login.tenant.store'), [
            'email' => $email,
            'password' => 'wrong',
        ])->assertInvalid('email');
    }

    post(route('panel.login.tenant.store'), [
        'email' => $email,
        'password' => 'wrong',
    ])->assertStatus(429);
});

it('logs out a user', function () {
    actingAs($this->user)
        ->post(route('panel.logout'))
        ->assertRedirect('/');

    $this->assertGuest('web');
});

it('redirects guest to login when accessing portal routes', function () {
    get(route('panel.dashboard'))
        ->assertRedirect(route('panel.login'));
});

it('allows authenticated user with tenant to access dashboard', function () {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenant->id])
        ->get(route('panel.dashboard'))
        ->assertOk();
});

it('allows authenticated user to access portal pages', function () {
    $routes = [
        'inbound-routes.index', 'outbound-routes.index',
    ];

    actingAs($this->user)->withSession(['selected_tenant_id' => (string) $this->tenant->id]);

    foreach ($routes as $route) {
        get(route('panel.'.$route))->assertOk();
    }
});
