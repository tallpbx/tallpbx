<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\OutboundRoutes\Livewire\OutboundRoutesList;
use Modules\OutboundRoutes\Models\OutboundRoute;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the outbound routes list component', function () {
    OutboundRoute::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesList::class)
        ->assertOk()
        ->assertSee('Outbound Routes')
        ->assertViewHas('routes', function ($routes) {
            return $routes->count() === 2;
        });
});

it('displays route name and dial pattern', function () {
    OutboundRoute::factory()->create([
        'name' => 'US Long Distance',
        'dial_pattern' => '^(\\d{10})$',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesList::class)
        ->assertSee('US Long Distance')
        ->assertSee('^(\\d{10})$');
});

it('deletes an outbound route', function () {
    $route = OutboundRoute::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesList::class)
        ->call('deleteRoute', $route->id)
        ->assertDispatched('route-deleted');

    $this->assertModelMissing($route);
});

it('opens the shared confirmation modal before deleting an outbound route', function (): void {
    $route = OutboundRoute::factory()->create(['name' => 'Long distance']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesList::class)
        ->call('confirmRouteDeletion', $route->id)
        ->assertSet('pendingDeletionId', $route->id)
        ->assertSet('pendingDeletionName', 'Long distance')
        ->assertSee('Delete Outbound Route?');
});

it('shows empty state when no routes exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesList::class)
        ->assertSee(__('admin.no_outbound_routes_found'));
});

it('admin sees routes from all tenants', function () {
    $otherTenant = Tenant::factory()->create();
    OutboundRoute::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Tenant A Route',
    ]);
    OutboundRoute::factory()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Tenant B Route',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesList::class)
        ->assertSee('Tenant A Route')
        ->assertSee('Tenant B Route');
});

it('tenant user sees only their own routes', function () {
    $otherTenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $this->tenant->users()->attach($user, ['role' => 'admin']);

    app(TenantManager::class)->setTenantId((string) $this->tenant->id);

    OutboundRoute::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'My Route',
    ]);
    OutboundRoute::factory()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Other Route',
    ]);

    Livewire::actingAs($user, 'web')
        ->test(OutboundRoutesList::class)
        ->assertSee('My Route')
        ->assertDontSee('Other Route');

    app(TenantManager::class)->setTenantId(null);
});

it('tenant user cannot delete routes', function () {
    $user = User::factory()->create();
    $this->tenant->users()->attach($user, ['role' => 'admin']);

    app(TenantManager::class)->setTenantId((string) $this->tenant->id);

    $route = OutboundRoute::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    Livewire::actingAs($user, 'web')
        ->test(OutboundRoutesList::class)
        ->call('deleteRoute', $route->id)
        ->assertForbidden();

    $this->assertModelExists($route);

    app(TenantManager::class)->setTenantId(null);
});
