<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    // Create the permission and grant it to a group
    $this->permission = Permission::factory()->create([
        'name' => 'extensions.view',
        'module' => 'extensions',
    ]);

    $this->group = Group::factory()->system()->create();
    $this->group->permissions()->attach($this->permission);
});

// ─── Route Enforcement (panel.can middleware) ────────────────────────────

it('allows access when admin has the required permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($this->group);

    actingAs($admin, 'admin')
        ->get(route('panel.extensions.index'))
        ->assertOk();
});

it('allows tenant users to access non-admin panel routes when they have permission', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['enabled' => true]);
    $tenant->users()->attach($user, ['role' => 'admin']);
    $user->groups()->attach($this->group);

    actingAs($user)
        ->withSession(['selected_tenant_id' => (string) $tenant->id])
        ->get(route('panel.extensions.index'))
        ->assertOk();
});

it('denies tenant users access to admin-only panel routes even if database state grants admin permissions', function () {
    $permission = Permission::factory()->create([
        'name' => 'admin.users.view',
        'module' => 'admin',
    ]);
    $group = Group::factory()->system()->create();
    $group->permissions()->attach($permission);

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['enabled' => true]);
    $tenant->users()->attach($user, ['role' => 'admin']);
    $user->groups()->attach($group);

    actingAs($user)
        ->withSession(['selected_tenant_id' => (string) $tenant->id])
        ->get(route('panel.users.index'))
        ->assertForbidden();
});

it('denies access when admin lacks the required permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.extensions.index'))
        ->assertForbidden();
});

it('denies access when admin lacks a create permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($this->group);

    // Admin has extensions.view but not extensions.create
    actingAs($admin, 'admin')
        ->get(route('panel.extensions.create'))
        ->assertForbidden();
});

it('allows access when admin has the exact create permission', function () {
    $perm = Permission::factory()->create([
        'name' => 'extensions.create',
        'module' => 'extensions',
    ]);
    $group = Group::factory()->system()->create();
    $group->permissions()->attach($perm);

    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($group);

    actingAs($admin, 'admin')
        ->get(route('panel.extensions.create'))
        ->assertOk();
});

it('redirects unauthenticated requests to login', function () {
    get(route('panel.extensions.index'))
        ->assertRedirect(route('panel.login'));
});

// ─── Gate Definition ────────────────────────────────────────────────────

it('defines gates for module-registered permissions', function () {
    // 'extensions.view' is registered by the extensions ModuleServiceProvider
    // and a Gate is defined for it via AppServiceProvider::booted()
    expect(Gate::has('extensions.view'))->toBeTrue();
    expect(Gate::has('admin.dashboard.view'))->toBeTrue();
    expect(Gate::has('inbound-routes.view'))->toBeTrue();
});

it('correctly denies permission via gate when admin lacks the role', function () {
    expect(Gate::has('extensions.view'))->toBeTrue();

    $admin = Admin::factory()->create(['enabled' => true]);

    expect($admin->can('extensions.view'))->toBeFalse();
});

// ─── N+1 Fix: hasPermission uses in-memory cache ────────────────────────

it('caches permission names after first call', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($this->group);

    // First call should load and cache
    $firstCall = $admin->hasPermission('extensions.view');
    expect($firstCall)->toBeTrue();

    // Second call should use cache (no additional queries)
    $secondCall = $admin->hasPermission('extensions.view');
    expect($secondCall)->toBeTrue();
});

it('returns false for non-existent permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($this->group);

    expect($admin->hasPermission('nonexistent.permission'))->toBeFalse();
});

// ─── Admin Settings & Modules Route Enforcement ─────────────────────────

it('allows access to admin settings with panel.settings.view permission', function () {
    $perm = Permission::factory()->create([
        'name' => 'admin.settings.view',
        'module' => 'admin',
    ]);
    $group = Group::factory()->system()->create();
    $group->permissions()->attach($perm);

    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($group);

    actingAs($admin, 'admin')
        ->get(route('panel.settings.index'))
        ->assertOk();
});

it('denies access to admin settings without panel.settings.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.settings.index'))
        ->assertForbidden();
});

it('allows access to admin modules with panel.modules.view permission', function () {
    $perm = Permission::factory()->create([
        'name' => 'admin.modules.view',
        'module' => 'admin',
    ]);
    $group = Group::factory()->system()->create();
    $group->permissions()->attach($perm);

    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($group);

    actingAs($admin, 'admin')
        ->get(route('panel.modules.index'))
        ->assertOk();
});

it('denies access to admin modules without panel.modules.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.modules.index'))
        ->assertForbidden();
});

it('redirects unauthenticated requests to admin settings login', function () {
    get(route('panel.settings.index'))
        ->assertRedirect(route('panel.login'));
});

it('redirects unauthenticated requests to admin modules login', function () {
    get(route('panel.modules.index'))
        ->assertRedirect(route('panel.login'));
});
