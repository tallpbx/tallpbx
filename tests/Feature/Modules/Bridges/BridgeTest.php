<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Bridges\Livewire\BridgesEdit;
use Modules\Bridges\Livewire\BridgesList;
use Modules\Bridges\Models\Bridge;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the bridges list component', function () {
    Bridge::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesList::class)
        ->assertOk()
        ->assertSee('Bridges')
        ->assertViewHas('bridges', function ($bridges) {
            return $bridges->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('bridgeName', '')
        ->assertSet('enabled', true);
});

it('creates a new bridge', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('bridgeName', 'Conference Room A')
        ->set('destinationNumber', '8000')
        ->call('save')
        ->assertRedirect(route('panel.bridges.index'));

    $this->assertDatabaseHas('bridges', [
        'bridge_name' => 'Conference Room A',
    ]);
});

it('updates an existing bridge', function () {
    $bridge = Bridge::factory()->create(['bridge_name' => 'Old Room']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesEdit::class, ['bridgeId' => $bridge->id])
        ->set('bridgeName', 'New Room')
        ->call('save')
        ->assertRedirect(route('panel.bridges.index'));

    $this->assertDatabaseHas('bridges', [
        'id' => $bridge->id,
        'bridge_name' => 'New Room',
    ]);
});

it('deletes a bridge', function () {
    $bridge = Bridge::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesList::class)
        ->call('deleteBridge', $bridge->id)
        ->assertDispatched('bridge-deleted');

    $this->assertModelMissing($bridge);
});

it('opens the shared confirmation modal before deleting a bridge', function (): void {
    $bridge = Bridge::factory()->create(['bridge_name' => 'Conference bridge']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesList::class)
        ->call('confirmBridgeDeletion', $bridge->id)
        ->assertSet('pendingDeletionId', $bridge->id)
        ->assertSet('pendingDeletionName', 'Conference bridge')
        ->assertSee('Delete Bridge?');
});

it('validates bridge name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesEdit::class)
        ->set('bridgeName', '')
        ->call('save')
        ->assertHasErrors(['bridgeName' => 'required']);
});

it('validates destination number is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesEdit::class)
        ->set('destinationNumber', '')
        ->call('save')
        ->assertHasErrors(['destinationNumber' => 'required']);
});

it('shows empty state when no bridges exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(BridgesList::class)
        ->assertSee('No bridges found.');
});
