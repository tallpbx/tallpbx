<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\ImpersonationServiceInterface;
use App\Support\PanelActor;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| Disabled Account Enforcement
|--------------------------------------------------------------------------
|
| A disabled account must fail closed on every request path: page requests
| through AuthPanelMiddleware, Livewire update requests through
| EnforcePanelLivewireActionPermissions, and every Gate/Policy check that
| resolves its actor through PanelActor.
|
*/

it('rejects a disabled administrator mid-session', function (): void {
    $admin = grantAdminPermissions(permissions: ['admin.dashboard.view']);

    $this->actingAs($admin, 'admin')->get(route('panel.dashboard'))->assertOk();

    $admin->update(['enabled' => false]);

    $this->get(route('panel.dashboard'))
        ->assertRedirect(route('panel.login'))
        ->assertSessionHasErrors('email');

    expect(PanelActor::resolve())->toBeNull();
});

it('rejects a disabled tenant user and makes every permission check fail closed', function (): void {
    $tenant = Tenant::factory()->create();
    $user = grantTenantUserPermissions($tenant, ['extensions.view']);

    $this->actingAs($user, 'web');

    expect(PanelActor::can('extensions.view'))->toBeTrue();

    $user->update(['enabled' => false]);

    $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));

    expect(PanelActor::resolve())->toBeNull()
        ->and(PanelActor::can('extensions.view'))->toBeFalse();
});

it('still lets an administrator stop impersonating a disabled user', function (): void {
    $admin = grantAdminPermissions(permissions: ['admin.impersonate', 'admin.dashboard.view']);
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach(Tenant::factory()->create());

    $this->actingAs($admin, 'admin');
    app(ImpersonationServiceInterface::class)->impersonate($user);

    $user->update(['enabled' => false]);

    $this->post(route('panel.impersonation.stop'))->assertRedirect();

    expect(Auth::guard('admin')->check())->toBeTrue();
});

it('ends impersonation when the impersonating administrator is disabled', function (): void {
    $admin = grantAdminPermissions(permissions: ['admin.impersonate', 'admin.dashboard.view']);
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach(Tenant::factory()->create());

    $this->actingAs($admin, 'admin');
    app(ImpersonationServiceInterface::class)->impersonate($user);

    $admin->update(['enabled' => false]);

    $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::guard('admin')->check())->toBeFalse()
        ->and(session(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID))->toBeNull();
});

it('rejects a disabled impersonating administrator on a Livewire action', function (): void {
    // Livewire updates never pass through AuthPanelMiddleware; they authenticate
    // through PanelActor::resolve(), which must therefore check the origin too.
    $admin = grantAdminPermissions(permissions: ['admin.impersonate', 'extensions.view']);
    $tenant = Tenant::factory()->create();
    $user = grantTenantUserPermissions($tenant, ['extensions.view']);

    $this->actingAs($admin, 'admin');
    app(ImpersonationServiceInterface::class)->impersonate($user);

    $admin->update(['enabled' => false]);

    expect(PanelActor::resolve())->toBeNull()
        ->and(PanelActor::can('extensions.view'))->toBeFalse();
});

it('keeps an enabled administrator session working', function (): void {
    // Control: the enablement check must not reject healthy sessions.
    $admin = grantAdminPermissions(permissions: ['admin.dashboard.view']);

    $this->actingAs($admin, 'admin')->get(route('panel.dashboard'))->assertOk();

    expect(PanelActor::resolve())->not->toBeNull();
});

it('resolves the impersonated user only while both accounts stay enabled', function (): void {
    $admin = grantAdminPermissions(permissions: ['admin.impersonate', 'admin.dashboard.view']);
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach(Tenant::factory()->create());

    $this->actingAs($admin, 'admin');
    app(ImpersonationServiceInterface::class)->impersonate($user);

    $resolved = PanelActor::resolve();

    expect($resolved)->toBeInstanceOf(User::class)
        ->and($resolved->getKey())->toBe($user->getKey())
        ->and($resolved instanceof Admin)->toBeFalse();
});

it('rotates the remember token when an account is disabled', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);
    $before = $admin->remember_token;

    $admin->update(['enabled' => false]);

    expect($admin->fresh()->remember_token)->not->toBe($before)
        ->and($admin->fresh()->remember_token)->toHaveLength(60);
});

it('leaves the remember token alone when other fields change', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);
    $before = $admin->remember_token;

    $admin->update(['name' => 'Renamed']);

    expect($admin->fresh()->remember_token)->toBe($before);
});

it('rejects a remember-me cookie issued before the account was disabled', function (): void {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach(Tenant::factory()->create());

    $login = $this->post(route('panel.login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $recaller = collect($login->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === Auth::guard('web')->getRecallerName());

    expect($recaller)->not->toBeNull();

    $user->update(['enabled' => false]);

    $this->flushSession()
        ->withCookie($recaller->getName(), $recaller->getValue());

    // Drop the in-memory guard left over from the login request so the next
    // request can only authenticate through the recaller cookie itself.
    $this->app['auth']->forgetGuards();

    $this->get(route('panel.dashboard'))
        ->assertRedirect(route('panel.login'));

    expect(Auth::guard('web')->check())->toBeFalse();
});
