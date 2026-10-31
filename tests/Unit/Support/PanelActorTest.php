<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Support\PanelActor;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('resolves the impersonated tenant user instead of the underlying admin when impersonation session is active', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    $this->actingAs($admin, 'admin');
    $this->actingAs($user, 'web');
    session()->put(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID, $admin->id);

    expect(PanelActor::isImpersonating())->toBeTrue()
        ->and(PanelActor::resolve()?->getAuthIdentifier())->toBe($user->id)
        ->and(PanelActor::resolve())->toBeInstanceOf(User::class)
        ->and(PanelActor::isAdmin())->toBeFalse()
        ->and(PanelActor::can('admin.users.view'))->toBeFalse();
});

it('resolves the admin actor when not impersonating and detects Super Administrators membership', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superGroup = Group::where('name', 'Super Administrators')->whereNull('tenant_id')->firstOrFail();
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($superGroup->id);

    $this->actingAs($admin, 'admin');

    expect(PanelActor::resolve())->toBeInstanceOf(Admin::class)
        ->and(PanelActor::isAdmin())->toBeTrue()
        ->and(PanelActor::isSuperAdmin())->toBeTrue();
});

it('detects when admin is not a super administrator', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $adminGroup = Group::where('name', 'Administrators')->whereNull('tenant_id')->firstOrFail();
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($adminGroup->id);

    $this->actingAs($admin, 'admin');

    expect(PanelActor::resolve())->toBeInstanceOf(Admin::class)
        ->and(PanelActor::isAdmin())->toBeTrue()
        ->and(PanelActor::isSuperAdmin())->toBeFalse();
});

it('resolves tenant user when logged in via web guard without impersonation', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    $this->actingAs($user, 'web');

    expect(PanelActor::resolve())->toBeInstanceOf(User::class)
        ->and(PanelActor::isImpersonating())->toBeFalse()
        ->and(PanelActor::isAdmin())->toBeFalse()
        ->and(PanelActor::isSuperAdmin())->toBeFalse();
});

it('returns null and false when unauthenticated', function () {
    expect(PanelActor::resolve())->toBeNull()
        ->and(PanelActor::isImpersonating())->toBeFalse()
        ->and(PanelActor::isAdmin())->toBeFalse()
        ->and(PanelActor::isSuperAdmin())->toBeFalse()
        ->and(PanelActor::can('any.ability'))->toBeFalse();
});

it('checks can() method correctly for admin abilities', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superGroup = Group::where('name', 'Super Administrators')->whereNull('tenant_id')->firstOrFail();
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($superGroup->id);

    $this->actingAs($admin, 'admin');

    expect(PanelActor::can('admin.users.view'))->toBeTrue()
        ->and(PanelActor::can('nonexistent.permission'))->toBeFalse();
});

it('checks can() method correctly for tenant user abilities', function () {
    $tenant = Tenant::factory()->create();
    $user = grantTenantUserPermissions($tenant, ['extensions.view']);

    $this->actingAs($user, 'web');

    expect(PanelActor::can('extensions.view'))->toBeTrue()
        ->and(PanelActor::can('admin.users.view'))->toBeFalse();
});

it('ensures ScopeTenant denies impersonating admin trying to access foreign tenant', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $tenant = Tenant::factory()->create();
    $foreignTenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    $this->actingAs($admin, 'admin');
    $this->actingAs($user, 'web');
    session()->put(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID, $admin->id);
    session()->put('selected_tenant_id', (string) $foreignTenant->id);

    $this->get(route('panel.dashboard'))
        ->assertForbidden();
});

it('ensures ProfileEdit resolves impersonated user instead of admin', function () {
    $admin = Admin::factory()->create(['name' => 'Original Admin', 'enabled' => true]);
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    $this->actingAs($admin, 'admin');
    $this->actingAs($user, 'web');
    session()->put(ImpersonationService::SESSION_ORIGINAL_ADMIN_ID, $admin->id);

    expect(PanelActor::resolve()?->id)->toBe($user->id)
        ->and(PanelActor::resolve()?->name)->toBe($user->name);
});
