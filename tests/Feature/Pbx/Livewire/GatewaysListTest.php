<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\Gateways\Livewire\GatewaysList;
use Modules\Gateways\Models\Gateway;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the gateways list component', function () {
    Gateway::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GatewaysList::class)
        ->assertOk()
        ->assertSee('Gateways')
        ->assertViewHas('gateways', function ($gateways) {
            return $gateways->count() === 3;
        });
});

it('displays gateway name and host', function () {
    Gateway::factory()->create([
        'name' => 'ITSP Outbound',
        'host' => 'sip.itsp.com',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GatewaysList::class)
        ->assertSee('ITSP Outbound')
        ->assertSee('sip.itsp.com');
});

it('deletes a gateway', function () {
    $gateway = Gateway::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(GatewaysList::class)
        ->call('deleteGateway', $gateway->id)
        ->assertDispatched('gateway-deleted');

    $this->assertModelMissing($gateway);
});

it('opens the shared confirmation modal before deleting a gateway', function (): void {
    $gateway = Gateway::factory()->create(['name' => 'Primary gateway']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(GatewaysList::class)
        ->call('confirmGatewayDeletion', $gateway->id)
        ->assertSet('pendingDeletionId', $gateway->id)
        ->assertSet('pendingDeletionName', 'Primary gateway')
        ->assertSee('Delete Gateway?');
});

it('shows empty state when no gateways exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(GatewaysList::class)
        ->assertSee('No gateways found');
});

it('shows registration status', function () {
    Gateway::factory()->create(['name' => 'Registered GW', 'register' => true]);
    Gateway::factory()->create(['name' => 'Unregistered GW', 'register' => false]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(GatewaysList::class);

    $gateways = $component->viewData('gateways');
    $registered = $gateways->firstWhere('name', 'Registered GW');
    $unregistered = $gateways->firstWhere('name', 'Unregistered GW');

    expect($registered->register)->toBeTrue()
        ->and($unregistered->register)->toBeFalse();
});
