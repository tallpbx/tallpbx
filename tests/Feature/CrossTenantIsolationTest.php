<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Modules\Extensions\Models\Extension;
use Modules\InboundRoutes\Models\InboundRoute;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    $this->user = User::factory()->create();
    $this->tenantA->users()->attach($this->user, ['role' => 'member']);
    $this->tenantB->users()->attach($this->user, ['role' => 'member']);
});

afterEach(function () {
    app(TenantManager::class)->clear();
});

// ─── Real-model BelongsToTenant scoping ──────────────────────────────────

it('scopes queries to the current tenant on real module models', function () {
    // Create ext for tenant A, then switch context and create for tenant B
    Extension::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'extension_number' => '101',
    ]);
    Extension::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'extension_number' => '102',
    ]);

    // Scoped to A — should only see ext 101
    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);
    $results = Extension::query()->get();

    expect($results)
        ->toHaveCount(1)
        ->and($results->first()->extension_number)->toBe('101');
});

it('scopes queries independently per model type', function () {
    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);

    Extension::factory()->create(['tenant_id' => $this->tenantA->id]);
    InboundRoute::factory()->create(['tenant_id' => $this->tenantA->id]);

    app(TenantManager::class)->setTenantId((string) $this->tenantB->id);

    expect(Extension::query()->count())->toBe(0);
    expect(InboundRoute::query()->count())->toBe(0);
});

it('withoutGlobalScope bypasses tenant scoping', function () {
    Extension::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'extension_number' => '101',
    ]);
    Extension::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'extension_number' => '102',
    ]);

    // Scoped to A, but bypass
    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);
    $all = Extension::withoutGlobalScope('tenant')->get();

    expect($all)->toHaveCount(2);
});

it('throws RuntimeException when querying without tenant context', function () {
    app(TenantManager::class)->clear();

    expect(fn () => Extension::query()->count())
        ->toThrow(RuntimeException::class, 'Tenant scope applied but no tenant context is set.');
});

it('auto-assigns tenant_id on model creation', function () {
    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);

    // Use direct create (without factory) so tenant_id is not pre-set
    $extension = Extension::create([
        'extension_number' => 'auto-100',
        'display_name' => 'Auto Assign Test',
    ]);

    expect((int) $extension->tenant_id)->toBe($this->tenantA->id);
});

// ─── Service layer respects tenant scoping ──────────────────────────────

it('service queries respect the active tenant scope', function () {
    InboundRoute::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Tenant A Route',
    ]);
    InboundRoute::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Route',
    ]);

    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);
    $results = InboundRoute::where('enabled', true)->get();

    expect($results)
        ->toHaveCount(1)
        ->and($results->first()->name)->toBe('Tenant A Route');
});

// ─── HTTP-level cross-tenant isolation ───────────────────────────────────

it('falls back to user default tenant when session tenant does not exist', function () {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => '999999'])
        ->get(route('panel.dashboard'))
        ->assertOk();

    // Should fall through to the authenticated user's default tenant
    expect(app(TenantManager::class)->getTenantId())->toBe((string) $this->tenantA->id);
});

it('returns 403 when user tries to access another tenant they dont belong to', function () {
    $unrelatedTenant = Tenant::factory()->create();

    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $unrelatedTenant->id])
        ->get(route('panel.dashboard'))
        ->assertForbidden();
});

it('allows access to tenant that user belongs to', function () {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenantA->id])
        ->get(route('panel.dashboard'))
        ->assertOk();
});

it('does not apply tenant scope to admin routes', function () {
    $admin = grantAdminPermissions(permissions: ['extensions.view']);

    Extension::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'extension_number' => '101',
    ]);
    Extension::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'extension_number' => '102',
    ]);

    actingAs($admin, 'admin')
        ->get(route('panel.extensions.index'))
        ->assertOk()
        ->assertSee('101')
        ->assertSee('102');
});

// ─── Switching tenants isolates data ─────────────────────────────────────

it('model factory can explicitly create for a different tenant', function () {
    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);

    $extension = Extension::factory()->create([
        'tenant_id' => $this->tenantB->id,
    ]);

    expect((int) $extension->tenant_id)->toBe($this->tenantB->id);
});

it('tenant scope prevents cross-tenant data leaks via counts', function () {
    Extension::factory()->count(3)->create([
        'tenant_id' => $this->tenantA->id,
    ]);
    Extension::factory()->count(5)->create([
        'tenant_id' => $this->tenantB->id,
    ]);

    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);

    expect(Extension::count())->toBe(3);
});

it('initializes tenant context on every shared panel route', function (): void {
    grantTenantUserPermissions($this->tenantB, ['inbound-routes.view'], $this->user);

    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenantB->id])
        ->get(route('panel.inbound-routes.index'))
        ->assertOk();

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $this->tenantB->id);
});

it('applies tenant group permissions only in their assigned tenant', function (): void {
    $tenantAPermission = Permission::factory()->create([
        'name' => 'extensions.view',
        'module' => 'extensions',
    ]);
    $tenantBPermission = Permission::factory()->create([
        'name' => 'inbound-routes.view',
        'module' => 'inbound-routes',
    ]);
    $tenantAGroup = Group::factory()->forTenant($this->tenantA->id)->create();
    $tenantBGroup = Group::factory()->forTenant($this->tenantB->id)->create();
    $tenantAGroup->permissions()->attach($tenantAPermission);
    $tenantBGroup->permissions()->attach($tenantBPermission);
    $this->user->groups()->attach([$tenantAGroup->id, $tenantBGroup->id]);

    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);

    expect($this->user->hasPermission('extensions.view'))->toBeTrue()
        ->and($this->user->hasPermission('inbound-routes.view'))->toBeFalse();

    app(TenantManager::class)->setTenantId((string) $this->tenantB->id);

    expect($this->user->hasPermission('extensions.view'))->toBeFalse()
        ->and($this->user->hasPermission('inbound-routes.view'))->toBeTrue();
});

it('never grants global admin permissions to a tenant user', function (): void {
    $permission = Permission::factory()->create([
        'name' => 'admin.users.view',
        'module' => 'admin',
    ]);
    $group = Group::factory()->forTenant($this->tenantA->id)->create();
    $group->permissions()->attach($permission);
    $this->user->groups()->attach($group);
    app(TenantManager::class)->setTenantId((string) $this->tenantA->id);

    expect($this->user->hasPermission('admin.users.view'))->toBeFalse();

    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenantA->id])
        ->get(route('panel.users.index'))
        ->assertForbidden();
});
