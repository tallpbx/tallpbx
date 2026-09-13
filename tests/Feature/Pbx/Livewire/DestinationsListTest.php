<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\Destinations\Livewire\DestinationsList;
use Modules\Destinations\Models\Destination;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the destinations list component', function () {
    Destination::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsList::class)
        ->assertOk()
        ->assertSee('Destinations')
        ->assertViewHas('destinations', function ($destinations) {
            return $destinations->count() === 3;
        });
});

it('displays destination name, type, and dial string', function () {
    Destination::factory()->create([
        'name' => 'Conference',
        'type' => 'conference',
        'dial_string' => '3000',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsList::class)
        ->assertSee('Conference')
        ->assertSee('conference')
        ->assertSee('3000');
});

it('deletes a destination', function () {
    $destination = Destination::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsList::class)
        ->call('deleteDestination', $destination->id)
        ->assertDispatched('destination-deleted');

    $this->assertModelMissing($destination);
});

it('opens the shared confirmation modal before deleting a destination', function (): void {
    $destination = Destination::factory()->create(['name' => 'Main reception']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsList::class)
        ->call('confirmDestinationDeletion', $destination->id)
        ->assertSet('pendingDeletionId', $destination->id)
        ->assertSet('pendingDeletionName', 'Main reception')
        ->assertSee('Delete Destination?');
});

it('shows empty state when no destinations exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DestinationsList::class)
        ->assertSee('No destinations found');
});
