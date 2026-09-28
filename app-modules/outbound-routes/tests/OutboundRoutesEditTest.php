<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Gateways\Models\Gateway;
use Modules\OutboundRoutes\Livewire\OutboundRoutesEdit;
use Modules\OutboundRoutes\Models\OutboundRoute;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class)
        ->assertOk()
        ->assertSee('Create Outbound Route')
        ->assertSet('name', '')
        ->assertSet('priority', 100)
        ->assertSet('enabled', true);
});

it('renders the edit form with existing route data', function () {
    $route = OutboundRoute::factory()->create([
        'name' => 'US Long Distance',
        'dial_pattern' => '^(\\d{10})$',
        'gateway' => 'sip-provider',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class, ['routeId' => $route->id])
        ->assertOk()
        ->assertSee('Edit Outbound Route')
        ->assertSet('name', 'US Long Distance')
        ->assertSet('dialPattern', '^(\\d{10})$')
        ->assertSet('gateway', 'sip-provider');
});

it('creates a new outbound route', function () {
    $gateway = Gateway::factory()->create([
        'tenant_id' => $this->tenant->id,
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'International')
        ->set('dialPattern', '^(011\\d+)$')
        ->set('gatewayId', $gateway->id)
        ->call('save')
        ->assertRedirect(route('panel.outbound-routes.index'));

    $this->assertDatabaseHas('outbound_routes', [
        'name' => 'International',
        'dial_pattern' => '^(011\\d+)$',
        'gateway_id' => $gateway->id,
    ]);
});

it('updates an existing outbound route', function () {
    $route = OutboundRoute::factory()->create(['name' => 'Old Route', 'priority' => 100]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class, ['routeId' => $route->id])
        ->set('name', 'Updated Route')
        ->set('priority', 50)
        ->call('save')
        ->assertRedirect(route('panel.outbound-routes.index'));

    $this->assertDatabaseHas('outbound_routes', [
        'id' => $route->id,
        'name' => 'Updated Route',
        'priority' => 50,
    ]);
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates dial pattern is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class)
        ->set('dialPattern', '')
        ->call('save')
        ->assertHasErrors(['dialPattern' => 'required']);
});

it('validates dial pattern has a capturing group', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'No Capture')
        ->set('dialPattern', '^011\\d+$')
        ->call('save')
        ->assertHasErrors(['dialPattern']);
});

it('rejects gateways from another tenant', function () {
    $otherTenant = Tenant::factory()->create();
    $gateway = Gateway::factory()->create([
        'tenant_id' => $otherTenant->id,
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Cross Tenant Gateway')
        ->set('dialPattern', '^(\\d{10})$')
        ->set('gatewayId', $gateway->id)
        ->call('save')
        ->assertHasErrors(['gatewayId']);
});

it('rejects disabled gateways', function () {
    $gateway = Gateway::factory()->create([
        'tenant_id' => $this->tenant->id,
        'enabled' => false,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OutboundRoutesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Disabled Gateway')
        ->set('dialPattern', '^(\\d{10})$')
        ->set('gatewayId', $gateway->id)
        ->call('save')
        ->assertHasErrors(['gatewayId']);
});
