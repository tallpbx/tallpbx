<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\MusicOnHold\Livewire\MusicOnHoldEdit;
use Modules\MusicOnHold\Livewire\MusicOnHoldList;
use Modules\MusicOnHold\Models\MusicOnHold;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the music on hold list component', function () {
    MusicOnHold::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(MusicOnHoldList::class)
        ->assertOk()
        ->assertSee('Music on Hold')
        ->assertViewHas('holdMusics', function ($holdMusics) {
            return $holdMusics->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(MusicOnHoldEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('name', '')
        ->assertSet('enabled', true);
});

it('creates a new music on hold entry', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(MusicOnHoldEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Soft Jazz')
        ->call('save')
        ->assertRedirect(route('panel.music-on-hold.index'));

    $this->assertDatabaseHas('music_on_hold', [
        'name' => 'Soft Jazz',
    ]);
});

it('updates an existing music on hold entry', function () {
    $moh = MusicOnHold::factory()->create(['name' => 'Old Tune']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(MusicOnHoldEdit::class, ['mohId' => $moh->id])
        ->set('name', 'New Tune')
        ->call('save')
        ->assertRedirect(route('panel.music-on-hold.index'));

    $this->assertDatabaseHas('music_on_hold', [
        'id' => $moh->id,
        'name' => 'New Tune',
    ]);
});

it('deletes a music on hold entry', function () {
    $moh = MusicOnHold::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(MusicOnHoldList::class)
        ->call('confirmMusicOnHoldDeletion', $moh->id)
        ->assertSet('pendingDeletionId', $moh->id)
        ->call('deleteMusicOnHold')
        ->assertSet('operationalMessage', 'Music on hold deleted.')
        ->assertDispatched('moh-deleted');

    $this->assertModelMissing($moh);
});

it('opens the shared confirmation modal before deleting a music on hold entry', function (): void {
    $moh = MusicOnHold::factory()->create(['name' => 'Soft jazz']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(MusicOnHoldList::class)
        ->call('confirmMusicOnHoldDeletion', $moh->id)
        ->assertSet('pendingDeletionId', $moh->id)
        ->assertSet('pendingDeletionName', 'Soft jazz')
        ->assertSee('Delete Music on Hold?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(MusicOnHoldEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('shows empty state when no music on hold entries exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(MusicOnHoldList::class)
        ->assertSee('No music files found.');
});
