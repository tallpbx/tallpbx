<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\ImpersonationLog;
use App\Models\Permission;
use App\Models\User;
use App\Services\PermissionService;
use Livewire\Livewire;
use Modules\Admin\Livewire\ImpersonationLogsList;

beforeEach(function () {
    app(PermissionService::class)->register('admin', ['admin.impersonate', 'admin.users.view']);
    app(PermissionService::class)->syncToDatabase();
});

/**
 * Helper to grant an admin a specific permission via a system group.
 */
function grantAdminPerm(Admin $admin, string $permissionName): void
{
    $group = Group::factory()->system()->create();
    $perm = Permission::where('name', $permissionName)->firstOrFail();
    $group->permissions()->attach($perm);
    $admin->groups()->attach($group);
}

it('allows admin with impersonate permission to access impersonation audit logs', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    grantAdminPerm($admin, 'admin.impersonate');

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

it('forbids admin without impersonate permission from viewing audit logs', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    // Do NOT give admin.impersonate permission

    $response = $this->actingAs($admin, 'admin')
        ->get(route('panel.impersonation-logs.index'));

    $response->assertForbidden();
});

it('forbids tenant user from accessing impersonation audit logs', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')
        ->get(route('panel.impersonation-logs.index'));

    // Tenant user has no admin.impersonate permission, so request is rejected
    $response->assertForbidden();
});

it('filters impersonation logs by search term', function () {
    $admin = Admin::factory()->create(['name' => 'Alice Admin', 'enabled' => true]);
    grantAdminPerm($admin, 'admin.impersonate');

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

it('filters impersonation logs by action type', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    grantAdminPerm($admin, 'admin.impersonate');

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
