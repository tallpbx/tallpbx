<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Exceptions\ImpersonationException;
use App\Models\Admin;
use App\Models\Group;
use App\Models\ImpersonationLog;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\ImpersonationServiceInterface;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Admin\Livewire\ImpersonationLogsList;

beforeEach(function (): void {
    app(PermissionService::class)->register('admin', ['admin.impersonate', 'admin.users.view']);
    app(PermissionService::class)->syncToDatabase();
});

/**
 * Helper to give an admin the impersonate permission via a system group.
 */
function giveAdminImpersonatePermission(Admin $admin): void
{
    $group = Group::factory()->system()->create();
    $perm = Permission::where('name', 'admin.impersonate')->firstOrFail();
    $group->permissions()->attach($perm);
    $admin->groups()->attach($group);
}

/*
|--------------------------------------------------------------------------
| Impersonation Lifecycle & Guard Behavior Tests
|--------------------------------------------------------------------------
*/

describe('impersonation lifecycle', function (): void {
    it('allows admin with permission to impersonate a user', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('panel.users.impersonate', $user));

        $response->assertRedirect(route('panel.dashboard'));

        $this->assertAuthenticatedAs($user, 'web');
        expect(session(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID))->toBe($admin->id);
        expect(session(ImpersonationService::SESSION_TARGET_USER_ID))->toBe($user->id);

        $this->assertDatabaseHas('impersonation_logs', [
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'action' => 'start',
        ]);
    });

    it('rejects impersonation when admin lacks permission', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('panel.users.impersonate', $user));

        $response->assertForbidden();
        $this->assertGuest('web');
        $this->assertDatabaseMissing('impersonation_logs', [
            'admin_id' => $admin->id,
            'action' => 'start',
        ]);
    });

    it('stops impersonation and restores admin session', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        expect($impersonationService->isImpersonating())->toBeTrue();

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

    it('allows an impersonated tenant user without admin permissions to stop impersonation', function (): void {
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

    it('rejects impersonation stop when admin was disabled during session', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        $admin->update(['enabled' => false]);

        expect(fn () => $impersonationService->stop())
            ->toThrow(ImpersonationException::class, 'Your admin account has been disabled.');

        $this->assertDatabaseHas('impersonation_logs', [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'action' => 'stop',
        ]);

        $this->assertGuest('web');
    });

    it('prevents an impersonated session from impersonating another user', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user1);

        $response = $this->post(route('panel.users.impersonate', $user2));

        $response->assertForbidden();
        $this->assertAuthenticatedAs($user1, 'web');
    });

    it('cannot impersonate another admin', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $anotherAdmin = Admin::factory()->create(['enabled' => true]);
        giveAdminImpersonatePermission($admin);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('panel.users.impersonate', $anotherAdmin));

        $response->assertNotFound();
    });

    it('logs stop action when admin ends impersonation via service', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        $this->assertDatabaseHas('impersonation_logs', [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'action' => 'start',
        ]);

        $impersonationService->stop();

        $this->assertDatabaseHas('impersonation_logs', [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'action' => 'stop',
        ]);

        expect($impersonationService->isImpersonating())->toBeFalse();
    });

    it('returns correct impersonation state', function (): void {
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

    it('requires authentication to impersonate', function (): void {
        $user = User::factory()->create();

        $response = $this->post(route('panel.users.impersonate', $user));

        $response->assertRedirect(route('panel.login'));
    });

    it('guarantees no notifications are sent to the target user when impersonated', function (): void {
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

        $this->post(route('panel.impersonation.stop'));

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        expect($user->notifications()->count())->toBe(0);
    });

    it('writes structured audit records to application logs on start and stop', function (): void {
        Log::spy();

        $admin = Admin::factory()->create(['name' => 'Audit Admin', 'enabled' => true]);
        $user = User::factory()->create(['email' => 'audituser@example.com']);
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($admin, $user): bool {
            return str_contains($message, 'Audit Admin')
                && str_contains($message, 'audituser@example.com')
                && ($context['event'] ?? '') === 'impersonation.start'
                && ($context['admin_id'] ?? null) === $admin->id
                && ($context['user_id'] ?? null) === $user->id;
        })->once();

        $impersonationService->stop();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($admin, $user): bool {
            return str_contains($message, 'Audit Admin')
                && str_contains($message, 'audituser@example.com')
                && ($context['event'] ?? '') === 'impersonation.stop'
                && ($context['admin_id'] ?? null) === $admin->id
                && ($context['user_id'] ?? null) === $user->id;
        })->once();
    });

    it('rotates the session id when impersonation starts', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        session()->setId(str_repeat('a', 40));
        session()->start();

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        expect(session()->getId())->not->toBe(str_repeat('a', 40))
            ->and(auth('web')->id())->toBe($user->id);
    });

    it('rotates the session id when impersonation stops', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        $impersonatingSessionId = session()->getId();
        $impersonationService->stop();

        expect(session()->getId())->not->toBe($impersonatingSessionId)
            ->and(auth('admin')->id())->toBe($admin->id);
    });

    it('stops safely when the original admin was deleted mid-session', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        $admin->delete();

        expect(fn () => $impersonationService->stop())
            ->toThrow(ImpersonationException::class, 'no longer exists');

        expect($impersonationService->isImpersonating())->toBeFalse();
        $this->assertGuest('web');
    });

    it('escapes user and admin display names in the impersonation banner to prevent stored XSS', function (): void {
        $tenant = Tenant::factory()->create();
        $admin = Admin::factory()->create([
            'name' => '<script>alert("admin-xss")</script>',
            'enabled' => true,
        ]);
        $user = User::factory()->create([
            'name' => '<img src=x onerror=alert("user-xss")>',
        ]);
        $tenant->users()->attach($user, ['role' => 'admin']);
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        $response = $this->withSession(['selected_tenant_id' => (string) $tenant->id])
            ->get(route('panel.dashboard'));
        $response->assertOk();
        $response->assertDontSee('<script>alert("admin-xss")</script>', false);
        $response->assertDontSee('<img src=x onerror=alert("user-xss")>', false);
        $response->assertSee('&lt;script&gt;alert(&quot;admin-xss&quot;)&lt;/script&gt;', false);
    });

    it('allows stopping impersonation via GET request to panel.impersonation.stop', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        $response = $this->actingAs($user, 'web')
            ->get(route('panel.impersonation.stop'));

        $response->assertRedirect(route('panel.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
        expect($impersonationService->isImpersonating())->toBeFalse();
    });

    it('synchronizes selected_tenant_id to target user default enabled tenant on start and clears it on stop', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        $tenant2->users()->attach($user, ['role' => 'admin']);
        giveAdminImpersonatePermission($admin);

        $this->actingAs($admin, 'admin');
        session()->put('selected_tenant_id', (string) $tenant1->id);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $impersonationService->impersonate($user);

        expect(session('selected_tenant_id'))->toBe((string) $tenant2->id);

        $impersonationService->stop();

        expect(session('selected_tenant_id'))->toBeNull();
    });

    it('allows stopping impersonation without 403 even when selected_tenant_id does not belong to impersonated user', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        // Intentionally inject an invalid/mismatched tenant in session
        $response = $this->actingAs($user, 'web')
            ->withSession(['selected_tenant_id' => '999999'])
            ->get(route('panel.impersonation.stop'));

        $response->assertRedirect(route('panel.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
        expect($impersonationService->isImpersonating())->toBeFalse();
    });

    it('renders emergency stop impersonation button on 403 error page during impersonation', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        $tenant = Tenant::factory()->create(['enabled' => true]);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['role' => 'member']);
        giveAdminImpersonatePermission($admin);

        $impersonationService = app(ImpersonationServiceInterface::class);
        $this->actingAs($admin, 'admin');
        $impersonationService->impersonate($user);

        // Attempting to access an admin-only route will return a 403 response
        $response = $this->actingAs($user, 'web')
            ->withSession(['selected_tenant_id' => (string) $tenant->id])
            ->get(route('panel.impersonation-logs.index'));

        $response->assertForbidden();
        $response->assertSee(route('panel.impersonation.stop'));
        $response->assertSee(__('client.stop_impersonating'));
    });

    it('does not render stop impersonation button on 403 error page when not impersonating', function (): void {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['enabled' => true]);
        $tenant->users()->attach($user, ['role' => 'member']);

        $response = $this->actingAs($user, 'web')
            ->withSession(['selected_tenant_id' => (string) $tenant->id])
            ->get(route('panel.impersonation-logs.index'));

        $response->assertForbidden();
        $response->assertDontSee(route('panel.impersonation.stop'));
        $response->assertDontSee(__('client.stop_impersonating'));
    });
});

/*
|--------------------------------------------------------------------------
| Impersonation Audit Logs & Livewire Component Tests
|--------------------------------------------------------------------------
*/

describe('impersonation log viewer', function (): void {
    it('allows admin with impersonate permission to access impersonation audit logs', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        giveAdminImpersonatePermission($admin);

        $targetUser = User::factory()->create(['email' => 'target@example.com']);
        ImpersonationLog::create([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'user_id' => $targetUser->id,
            'user_email' => $targetUser->email,
            'action' => 'start',
            'ip_address' => '192.168.1.100',
            'user_agent' => 'TestBrowser',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('panel.impersonation-logs.index'));

        $response->assertOk();
        $response->assertSeeText($admin->name);
        $response->assertSeeText($targetUser->email);
        $response->assertSeeText('192.168.1.100');
    });

    it('forbids admin without impersonate permission from viewing audit logs', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('panel.impersonation-logs.index'));

        $response->assertForbidden();
    });

    it('forbids tenant user from accessing impersonation audit logs', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->get(route('panel.impersonation-logs.index'));

        $response->assertForbidden();
    });

    it('filters impersonation logs by search term', function (): void {
        $admin = Admin::factory()->create(['name' => 'Alice Admin', 'enabled' => true]);
        giveAdminImpersonatePermission($admin);

        $user1 = User::factory()->create(['email' => 'charlie@tenant1.test']);
        $user2 = User::factory()->create(['email' => 'bob@tenant2.test']);

        ImpersonationLog::create([
            'admin_id' => $admin->id,
            'admin_name' => 'Alice Admin',
            'user_id' => $user1->id,
            'user_email' => $user1->email,
            'action' => 'start',
            'ip_address' => '10.0.0.1',
        ]);

        ImpersonationLog::create([
            'admin_id' => $admin->id,
            'admin_name' => 'Alice Admin',
            'user_id' => $user2->id,
            'user_email' => $user2->email,
            'action' => 'start',
            'ip_address' => '10.0.0.2',
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ImpersonationLogsList::class)
            ->assertSee('charlie@tenant1.test')
            ->assertSee('bob@tenant2.test')
            ->set('search', 'charlie')
            ->assertSee('charlie@tenant1.test')
            ->assertDontSee('bob@tenant2.test');
    });

    it('filters impersonation logs by action type', function (): void {
        $admin = Admin::factory()->create(['enabled' => true]);
        giveAdminImpersonatePermission($admin);

        $user = User::factory()->create(['email' => 'filtertest@example.com']);

        ImpersonationLog::create([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'action' => 'start',
            'ip_address' => '10.0.0.5',
        ]);

        ImpersonationLog::create([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'action' => 'stop',
            'ip_address' => '10.0.0.5',
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ImpersonationLogsList::class)
            ->set('actionFilter', 'start')
            ->assertSee('Started')
            ->set('actionFilter', 'stop')
            ->assertSee('Stopped');
    });
});
