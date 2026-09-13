<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\ConferenceCenters\Livewire\ConferenceCentersEdit;
use Modules\ConferenceCenters\Livewire\ConferenceCentersList;
use Modules\ConferenceCenters\Models\ConferenceCenter;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the conference centers list component', function () {
    ConferenceCenter::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersList::class)
        ->assertOk()
        ->assertSee('Conference Centers')
        ->assertViewHas('conferenceCenters', function ($centers) {
            return $centers->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('enabled', true);
});

it('creates a new conference center', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Main Conference Center')
        ->set('extension', '5000')
        ->call('save')
        ->assertRedirect(route('panel.conference-centers.index'));

    $this->assertDatabaseHas('conference_centers', [
        'name' => 'Main Conference Center',
        'extension' => '5000',
    ]);
});

it('updates an existing conference center', function () {
    $center = ConferenceCenter::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersEdit::class, ['conferenceCenterId' => $center->id])
        ->set('name', 'Updated Center')
        ->set('pin', '9999')
        ->call('save')
        ->assertRedirect(route('panel.conference-centers.index'));

    $this->assertDatabaseHas('conference_centers', [
        'id' => $center->id,
        'name' => 'Updated Center',
        'pin' => '9999',
    ]);
});

it('deletes a conference center', function () {
    $center = ConferenceCenter::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersList::class)
        ->call('confirmConferenceCenterDeletion', $center->id)
        ->assertSet('pendingDeletionId', $center->id)
        ->call('deleteConferenceCenter')
        ->assertSet('operationalMessage', 'Conference center deleted.')
        ->assertDispatched('conference-center-deleted');

    $this->assertModelMissing($center);
});

it('opens the shared confirmation modal before deleting a conference center', function (): void {
    $center = ConferenceCenter::factory()->create(['name' => 'Main hall']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersList::class)
        ->call('confirmConferenceCenterDeletion', $center->id)
        ->assertSet('pendingDeletionId', $center->id)
        ->assertSet('pendingDeletionName', 'Main hall')
        ->assertSee('Delete Conference Center?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates extension is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersEdit::class)
        ->set('extension', '')
        ->call('save')
        ->assertHasErrors(['extension' => 'required']);
});

it('shows empty state when no conference centers exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferenceCentersList::class)
        ->assertSee('No conference centers found');
});
