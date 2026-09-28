<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Conferences\Livewire\ConferencesEdit;
use Modules\Conferences\Livewire\ConferencesList;
use Modules\Conferences\Models\Conference;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the conferences list component', function () {
    Conference::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferencesList::class)
        ->assertOk()
        ->assertSee('Conferences')
        ->assertViewHas('conferences', function ($conferences) {
            return $conferences->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferencesEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('profile', 'sample')
        ->assertSet('maxMembers', 100)
        ->assertSet('enabled', true);
});

it('creates a new conference', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferencesEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Weekly Standup')
        ->set('profile', 'sample')
        ->set('pin', '1234')
        ->call('save')
        ->assertRedirect(route('panel.conferences.index'));

    $this->assertDatabaseHas('conferences', [
        'name' => 'Weekly Standup',
        'profile' => 'sample',
        'pin' => '1234',
    ]);
});

it('updates an existing conference', function () {
    $conference = Conference::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferencesEdit::class, ['conferenceId' => $conference->id])
        ->set('name', 'Updated Name')
        ->set('pin', '5678')
        ->call('save')
        ->assertRedirect(route('panel.conferences.index'));

    $this->assertDatabaseHas('conferences', [
        'id' => $conference->id,
        'name' => 'Updated Name',
        'pin' => '5678',
    ]);
});

it('deletes a conference', function () {
    $conference = Conference::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferencesList::class)
        ->call('deleteConference', $conference->id)
        ->assertDispatched('conference-deleted');

    $this->assertModelMissing($conference);
});

it('opens the shared confirmation modal before deleting a conference', function (): void {
    $conference = Conference::factory()->create(['name' => 'Weekly standup']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferencesList::class)
        ->call('confirmConferenceDeletion', $conference->id)
        ->assertSet('pendingDeletionId', $conference->id)
        ->assertSet('pendingDeletionName', 'Weekly standup')
        ->assertSee('Delete Conference?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferencesEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('shows empty state when no conferences exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ConferencesList::class)
        ->assertSee('No conferences found');
});
