<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Destinations\Livewire\DestinationsEdit;
use Modules\Destinations\Models\Destination;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsEdit::class)
        ->assertOk()
        ->assertSee('Create Destination')
        ->assertSet('name', '')
        ->assertSet('type', '')
        ->assertSet('dialString', '');
});

it('renders the edit form with existing data', function () {
    $destination = Destination::factory()->create([
        'name' => 'Conference',
        'type' => 'conference',
        'dial_string' => '3000',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsEdit::class, ['destinationId' => $destination->id])
        ->assertOk()
        ->assertSee('Edit Destination')
        ->assertSet('name', 'Conference')
        ->assertSet('type', 'conference')
        ->assertSet('dialString', '3000');
});

it('creates a new destination', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'IVR Main')
        ->set('type', 'ivr')
        ->set('dialString', '5000')
        ->call('save')
        ->assertRedirect(route('panel.destinations.index'));

    $this->assertDatabaseHas('destinations', [
        'name' => 'IVR Main',
        'type' => 'ivr',
        'dial_string' => '5000',
    ]);
});

it('updates an existing destination', function () {
    $destination = Destination::factory()->create(['name' => 'Old']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsEdit::class, ['destinationId' => $destination->id])
        ->set('name', 'Updated Dest')
        ->set('type', 'voicemail')
        ->set('dialString', '6000')
        ->call('save')
        ->assertRedirect(route('panel.destinations.index'));

    $this->assertDatabaseHas('destinations', [
        'id' => $destination->id,
        'name' => 'Updated Dest',
        'type' => 'voicemail',
    ]);
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates type is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsEdit::class)
        ->set('name', 'Test')
        ->set('type', '')
        ->call('save')
        ->assertHasErrors(['type' => 'required']);
});
