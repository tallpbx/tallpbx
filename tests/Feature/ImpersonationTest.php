<?php

declare(strict_types=1);

use App\Exceptions\ImpersonationException;
use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use App\Services\ImpersonationServiceInterface;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // Register the impersonate permission in memory
    app(PermissionService::class)->register('admin', ['admin.impersonate']);
    app(PermissionService::class)->syncToDatabase();
});

/**
 * Helper to give an admin the impersonate permission via a group.
 */
function giveAdminImpersonatePermission(Admin $admin): void
{
    $group = Group::factory()->system()->create();
    $perm = Permission::where('name', 'admin.impersonate')->firstOrFail();
    $group->permissions()->attach($perm);
    $admin->groups()->attach($group);
}

it('allows admin with permission to impersonate a user', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $user = User::factory()->create();
    giveAdminImpersonatePermission($admin);

    $response = $this->actingAs($admin, 'admin')
        ->post(route('panel.users.impersonate', $user));

    $response->assertRedirect(route('panel.dashboard'));

    // After redirect, the web guard should be authenticated as the user
    $this->assertAuthenticatedAs($user, 'web');
    expect(session('impersonation.original_admin_id'))->toBe($admin->id);
    expect(session('impersonation.target_user_id'))->toBe($user->id);

    $this->assertDatabaseHas('impersonation_logs', [
        'admin_id' => $admin->id,
        'admin_name' => $admin->name,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'action' => 'start',
    ]);
});

it('rejects impersonation when admin lacks permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $user = User::factory()->create();
    // Do NOT give impersonate permission to admin

    $response = $this->actingAs($admin, 'admin')
        ->post(route('panel.users.impersonate', $user));

    $response->assertForbidden();
    $this->assertGuest('web');
    $this->assertDatabaseMissing('impersonation_logs', [
        'admin_id' => $admin->id,
        'action' => 'start',
    ]);
});

it('stops impersonation and restores admin session', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $user = User::factory()->create();
    giveAdminImpersonatePermission($admin);

    // Start impersonation
    $impersonationService = app(ImpersonationServiceInterface::class);
    $this->actingAs($admin, 'admin');
    $impersonationService->impersonate($user);

    // Verify we're impersonating
    expect($impersonationService->isImpersonating())->toBeTrue();

    // Stop impersonation
    $response = $this->post(route('panel.impersonation.stop'));

    $response->assertRedirect(route('panel.dashboard'));
    $this->assertAuthenticatedAs($admin, 'admin');
    expect($impersonationService->isImpersonating())->toBeFalse();

    $this->assertDatabaseHas('impersonation_logs', [
        'admin_id' => $admin->id,
        'admin_name' => $admin->name,
        'user_id' => $user->id,
        'user_email' => $user->email,
        'action' => 'stop',
    ]);
});

it('allows an impersonated tenant user without admin permissions to stop impersonation', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $user = User::factory()->create();
    giveAdminImpersonatePermission($admin);

    $impersonationService = app(ImpersonationServiceInterface::class);
    $this->actingAs($admin, 'admin');
    $impersonationService->impersonate($user);

    expect($user->hasPermission('admin.impersonate'))->toBeFalse();

    $response = $this->actingAs($user, 'web')
        ->post(route('panel.impersonation.stop'));

    $response->assertRedirect(route('panel.dashboard'));
    $this->assertAuthenticatedAs($admin, 'admin');
    $this->assertGuest('web');
    expect($impersonationService->isImpersonating())->toBeFalse();

    $this->assertDatabaseHas('impersonation_logs', [
        'admin_id' => $admin->id,
        'user_id' => $user->id,
        'action' => 'stop',
    ]);
});

it('rejects impersonation stop when admin was disabled during session', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $user = User::factory()->create();
    giveAdminImpersonatePermission($admin);

    $impersonationService = app(ImpersonationServiceInterface::class);
    $this->actingAs($admin, 'admin');
    $impersonationService->impersonate($user);

    // Disable the admin while impersonation is active
    $admin->update(['enabled' => false]);

    // Attempting to stop should throw
    expect(fn () => $impersonationService->stop())
        ->toThrow(ImpersonationException::class, 'Your admin account has been disabled.');

    // Stop log should still be persisted even though re-auth was blocked
    $this->assertDatabaseHas('impersonation_logs', [
        'admin_id' => $admin->id,
        'user_id' => $user->id,
        'action' => 'stop',
    ]);

    // Web guard should be logged out (impersonation ended)
    $this->assertGuest('web');
});

it('prevents an impersonated session from impersonating another user', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    giveAdminImpersonatePermission($admin);

    $impersonationService = app(ImpersonationServiceInterface::class);
    $this->actingAs($admin, 'admin');
    $impersonationService->impersonate($user1);

    // Now try to impersonate user2 from within the impersonated session
    $response = $this->post(route('panel.users.impersonate', $user2));

    $response->assertForbidden();
    // Should still be user1
    $this->assertAuthenticatedAs($user1, 'web');
});

it('cannot impersonate another admin', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $anotherAdmin = Admin::factory()->create(['enabled' => true]);
    giveAdminImpersonatePermission($admin);

    $response = $this->actingAs($admin, 'admin')
        ->post(route('panel.users.impersonate', $anotherAdmin));

    $response->assertNotFound(); // User model binding won't find Admin
});

it('logs stop action when admin ends impersonation via service', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $user = User::factory()->create();
    giveAdminImpersonatePermission($admin);

    $impersonationService = app(ImpersonationServiceInterface::class);
    $this->actingAs($admin, 'admin');
    $impersonationService->impersonate($user);

    // Verify start log exists
    $this->assertDatabaseHas('impersonation_logs', [
        'admin_id' => $admin->id,
        'user_id' => $user->id,
        'action' => 'start',
    ]);

    $impersonationService->stop();

    // Verify stop log exists
    $this->assertDatabaseHas('impersonation_logs', [
        'admin_id' => $admin->id,
        'user_id' => $user->id,
        'action' => 'stop',
    ]);

    expect($impersonationService->isImpersonating())->toBeFalse();
});

it('returns correct impersonation state', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $user = User::factory()->create();
    giveAdminImpersonatePermission($admin);

    $impersonationService = app(ImpersonationServiceInterface::class);

    expect($impersonationService->isImpersonating())->toBeFalse();
    expect($impersonationService->getOriginalAdmin())->toBeNull();

    $this->actingAs($admin, 'admin');
    $impersonationService->impersonate($user);

    expect($impersonationService->isImpersonating())->toBeTrue();
    expect($impersonationService->getOriginalAdmin())->not->toBeNull();
    expect($impersonationService->getOriginalAdmin()->id)->toBe($admin->id);

    $impersonationService->stop();

    expect($impersonationService->isImpersonating())->toBeFalse();
    expect($impersonationService->getOriginalAdmin())->toBeNull();
});

it('requires authentication to impersonate', function () {
    $user = User::factory()->create();

    $response = $this->post(route('panel.users.impersonate', $user));

    $response->assertRedirect(route('panel.login'));
});

it('guarantees no notifications are sent to the target user when impersonated', function () {
    Notification::fake();
    Mail::fake();

    $admin = Admin::factory()->create(['enabled' => true]);
    $user = User::factory()->create();
    giveAdminImpersonatePermission($admin);

    $this->actingAs($admin, 'admin')
        ->post(route('panel.users.impersonate', $user));

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    expect($user->notifications()->count())->toBe(0);

    // Stop impersonation
    $this->post(route('panel.impersonation.stop'));

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    expect($user->notifications()->count())->toBe(0);
});

it('writes structured audit records to application logs on start and stop', function () {
    Log::spy();

    $admin = Admin::factory()->create(['name' => 'Audit Admin', 'enabled' => true]);
    $user = User::factory()->create(['email' => 'audituser@example.com']);
    giveAdminImpersonatePermission($admin);

    $impersonationService = app(ImpersonationServiceInterface::class);
    $this->actingAs($admin, 'admin');
    $impersonationService->impersonate($user);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($admin, $user) {
        return str_contains($message, 'Audit Admin')
            && str_contains($message, 'audituser@example.com')
            && ($context['event'] ?? '') === 'impersonation.start'
            && ($context['admin_id'] ?? null) === $admin->id
            && ($context['user_id'] ?? null) === $user->id;
    })->once();

    $impersonationService->stop();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($admin, $user) {
        return str_contains($message, 'Audit Admin')
            && str_contains($message, 'audituser@example.com')
            && ($context['event'] ?? '') === 'impersonation.stop'
            && ($context['admin_id'] ?? null) === $admin->id
            && ($context['user_id'] ?? null) === $user->id;
    })->once();
});

