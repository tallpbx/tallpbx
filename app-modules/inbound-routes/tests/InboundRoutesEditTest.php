<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\InboundRoutes\Livewire\InboundRoutesEdit;
use Modules\InboundRoutes\Models\InboundRoute;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesEdit::class)
        ->assertOk()
        ->assertSee('Create Inbound Route')
        ->assertSet('name', '')
        ->assertSet('priority', 100)
        ->assertSet('enabled', true);
});

it('renders the edit form with existing route data', function () {
    $route = InboundRoute::factory()->create([
        'name' => 'Main DID',
        'destination_number' => '+14155551212',
        'action' => 'transfer',
        'action_data' => '1000',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesEdit::class, ['routeId' => $route->id])
        ->assertOk()
        ->assertSee('Edit Inbound Route')
        ->assertSet('name', 'Main DID')
        ->assertSet('destinationNumber', '+14155551212')
        ->assertSet('action', 'transfer');
});

it('creates a new inbound route', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Support Line')
        ->set('destinationNumber', '+14155551234')
        ->set('action', 'transfer')
        ->set('actionData', '2000')
        ->call('save')
        ->assertRedirect(route('panel.inbound-routes.index'));

    $this->assertDatabaseHas('inbound_routes', [
        'name' => 'Support Line',
        'destination_number' => '+14155551234',
    ]);
});

it('updates an existing inbound route', function () {
    $route = InboundRoute::factory()->create(['name' => 'Old Route', 'priority' => 100]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesEdit::class, ['routeId' => $route->id])
        ->set('name', 'Updated Route')
        ->set('priority', 50)
        ->call('save')
        ->assertRedirect(route('panel.inbound-routes.index'));

    $this->assertDatabaseHas('inbound_routes', [
        'id' => $route->id,
        'name' => 'Updated Route',
        'priority' => 50,
    ]);
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates destination number is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(InboundRoutesEdit::class)
        ->set('destinationNumber', '')
        ->call('save')
        ->assertHasErrors(['destinationNumber' => 'required']);
});
