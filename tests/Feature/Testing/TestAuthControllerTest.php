<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
});

it('authenticates admin via test bridge when environment is testing', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);

    $response = $this->get("/_testing/login/admin/{$admin->id}");

    $response->assertRedirect('/panel');
    $this->assertAuthenticatedAs($admin, 'admin');
});

it('authenticates tenant user via test bridge when environment is testing', function (): void {
    $user = User::factory()->create(['enabled' => true]);

    $response = $this->get("/_testing/login/web/{$user->id}");

    $response->assertRedirect('/panel');
    $this->assertAuthenticatedAs($user, 'web');
});

it('signs out the opposite guard and clears stale tenant selection when switching identities', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($tenant, ['role' => 'admin', 'primary' => true]);

    // A system administrator signs in through the bridge first; in a real
    // browsing session the first panel page after that login stores the
    // selected tenant in the shared session.
    $this->get("/_testing/login/admin/{$admin->id}")->assertRedirect('/panel');
    session(['selected_tenant_id' => (string) $tenant->id]);

    // A tenant user then signs in through the same shared test session.
    $this->get("/_testing/login/web/{$user->id}")->assertRedirect('/panel');

    // The previous identity must not leak into the tenant user's requests:
    // the admin guard is signed out and the stale tenant selection is gone,
    // so the next request resolves the new user's own primary tenant instead
    // of aborting with 403 on a tenant the user does not belong to.
    $this->assertGuest('admin');
    $this->assertAuthenticatedAs($user, 'web');
    expect(session('selected_tenant_id'))->toBeNull();
});

it('returns 404 if environment is not testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $response = $this->get('/_testing/login/admin/1');

    $response->assertNotFound();
});
