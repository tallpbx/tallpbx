<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Bridges\Livewire\BridgesEdit;
use Modules\InboundRoutes\Models\InboundRoute;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->tenantA = Tenant::factory()->create(['name' => 'Alpha Tenant']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Beta Tenant']);
    $this->unrelatedTenant = Tenant::factory()->create(['name' => 'Unrelated Tenant']);

    $this->user->tenants()->attach($this->tenantA, ['primary' => true]);
    $this->user->tenants()->attach($this->tenantB);
});

it('shows the tenant switcher only when a user has multiple enabled tenants', function (): void {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenantA->id])
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSee('Alpha Tenant')
        ->assertSee('Beta Tenant')
        ->assertDontSee('Unrelated Tenant')
        ->assertSee('name="tenant_id"', false)
        ->assertSee(route('panel.tenant.switch'), false);

    $singleTenantUser = User::factory()->create();
    $singleTenantUser->tenants()->attach($this->tenantA, ['primary' => true]);

    actingAs($singleTenantUser)
        ->withSession(['selected_tenant_id' => (string) $this->tenantA->id])
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertDontSee('name="tenant_id"', false);
});

it('switches to another enabled tenant that belongs to the user', function (): void {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenantA->id])
        ->post(route('panel.tenant.switch'), [
            'tenant_id' => $this->tenantB->id,
        ])
        ->assertRedirect(route('panel.dashboard'))
        ->assertSessionHas('selected_tenant_id', (string) $this->tenantB->id);
});

it('rejects a forged switch to a tenant the user does not belong to', function (): void {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenantA->id])
        ->post(route('panel.tenant.switch'), [
            'tenant_id' => $this->unrelatedTenant->id,
        ])
        ->assertForbidden()
        ->assertSessionHas('selected_tenant_id', (string) $this->tenantA->id);
});

it('rejects switching to a disabled tenant', function (): void {
    $this->tenantB->update(['enabled' => false]);

    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenantA->id])
        ->post(route('panel.tenant.switch'), [
            'tenant_id' => $this->tenantB->id,
        ])
        ->assertForbidden()
        ->assertSessionHas('selected_tenant_id', (string) $this->tenantA->id);
});

it('does not expose the tenant switch route to admin-only sessions', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->post(route('panel.tenant.switch'), [
            'tenant_id' => $this->tenantA->id,
        ])
        ->assertRedirect(route('panel.login'))
        ->assertSessionMissing('selected_tenant_id');
});

it('uses the active tenant when a tenant user opens a shared edit component', function (): void {
    actingAs($this->user);
    app(TenantManager::class)->setTenantId((string) $this->tenantB->id);

    Livewire::test(BridgesEdit::class)
        ->assertSet('tenantId', $this->tenantB->id);
});

it('initializes the impersonated users tenant context when both guards are active', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);
    InboundRoute::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Alpha Route',
    ]);
    InboundRoute::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Beta Route',
    ]);

    grantTenantUserPermissions($this->tenantB, ['inbound-routes.view'], $this->user);

    actingAs($admin, 'admin');
    actingAs($this->user, 'web')
        ->withSession([
            'impersonation.original_admin_id' => $admin->id,
            'impersonation.target_user_id' => $this->user->id,
            'selected_tenant_id' => (string) $this->tenantB->id,
        ])
        ->get(route('panel.inbound-routes.index'))
        ->assertOk()
        ->assertSee('Beta Route')
        ->assertDontSee('Alpha Route');

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $this->tenantB->id);
});
