<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\InboundRoutes\Livewire\InboundRoutesList;
use Modules\InboundRoutes\Models\InboundRoute;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the inbound routes list component', function () {
    InboundRoute::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesList::class)
        ->assertOk()
        ->assertSee('Inbound Routes')
        ->assertViewHas('routes', function ($routes) {
            return $routes->count() === 2;
        });
});

it('displays route name and destination number', function () {
    InboundRoute::factory()->create([
        'name' => 'Main DID',
        'destination_number' => '+14155551212',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesList::class)
        ->assertSee('Main DID')
        ->assertSee('+14155551212');
});

it('deletes an inbound route', function () {
    $route = InboundRoute::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesList::class)
        ->call('deleteRoute', $route->id)
        ->assertDispatched('route-deleted');

    $this->assertModelMissing($route);
});

it('opens the shared confirmation modal before deleting an inbound route', function (): void {
    $route = InboundRoute::factory()->create(['name' => 'Main number']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesList::class)
        ->call('confirmRouteDeletion', $route->id)
        ->assertSet('pendingDeletionId', $route->id)
        ->assertSet('pendingDeletionName', 'Main number')
        ->assertSee('Delete Inbound Route?');
});

it('shows empty state when no routes exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesList::class)
        ->assertSee(__('admin.no_inbound_routes_found'));
});

it('admin sees routes from all tenants', function () {
    $otherTenant = Tenant::factory()->create();
    InboundRoute::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Tenant A Route',
    ]);
    InboundRoute::factory()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Tenant B Route',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesList::class)
        ->assertSee('Tenant A Route')
        ->assertSee('Tenant B Route');
});

it('tenant user sees only their own routes', function () {
    $otherTenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $this->tenant->users()->attach($user, ['role' => 'admin']);

    app(TenantManager::class)->setTenantId((string) $this->tenant->id);

    InboundRoute::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'My Route',
    ]);
    InboundRoute::factory()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Other Route',
    ]);

    Livewire::actingAs($user, 'web')
        ->test(InboundRoutesList::class)
        ->assertSee('My Route')
        ->assertDontSee('Other Route');

    app(TenantManager::class)->setTenantId(null);
});

it('tenant user cannot delete routes', function () {
    $user = User::factory()->create();
    $this->tenant->users()->attach($user, ['role' => 'admin']);

    app(TenantManager::class)->setTenantId((string) $this->tenant->id);

    $route = InboundRoute::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    Livewire::actingAs($user, 'web')
        ->test(InboundRoutesList::class)
        ->call('deleteRoute', $route->id)
        ->assertForbidden();

    $this->assertModelExists($route);

    app(TenantManager::class)->setTenantId(null);
});
