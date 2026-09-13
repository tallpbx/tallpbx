<?php

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    $this->tenant->users()->attach($this->user, ['role' => 'member']);
});

it('resolves tenant from session for tenant user', function () {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenant->id])
        ->get(route('panel.dashboard'))
        ->assertOk();

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $this->tenant->id);
});

it('resolves tenant from user default when no header or session', function () {
    actingAs($this->user)
        ->get(route('panel.dashboard'))
        ->assertOk();

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $this->tenant->id);
});

it('saves default tenant to session', function () {
    actingAs($this->user)
        ->get(route('panel.dashboard'));

    expect(session()->get('selected_tenant_id'))->toBe((string) $this->tenant->id);
});

it('returns 403 when user tries to access a tenant they dont belong to', function () {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->otherTenant->id])
        ->get(route('panel.dashboard'))
        ->assertForbidden();
});

it('falls back to another membership when the selected tenant is disabled', function (): void {
    $this->tenant->update(['enabled' => false]);
    $this->otherTenant->users()->attach($this->user, ['role' => 'member']);

    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenant->id])
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSessionHas('selected_tenant_id', (string) $this->otherTenant->id);
});

it('bypasses tenant scoping for admin routes', function () {
    $admin = grantAdminPermissions();

    actingAs($admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk();

    // Admin routes don't use the 'tenant' middleware, so the TenantManager should not be set
    expect(app(TenantManager::class)->hasTenant())->toBeFalse();
});

it('allows guest requests without tenant scoping', function () {
    get(route('panel.login.tenant'))
        ->assertOk();

    expect(app(TenantManager::class)->hasTenant())->toBeFalse();
});

it('fails closed when a tenant user has no enabled tenant membership', function (): void {
    $userWithoutTenant = User::factory()->create();

    actingAs($userWithoutTenant)
        ->get(route('panel.dashboard'))
        ->assertForbidden();

    expect(app(TenantManager::class)->hasTenant())->toBeFalse();
});
