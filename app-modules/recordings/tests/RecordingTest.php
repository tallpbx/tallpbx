<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Recordings\Livewire\RecordingsEdit;
use Modules\Recordings\Livewire\RecordingsList;
use Modules\Recordings\Models\Recording;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the recordings list component', function () {
    Recording::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsList::class)
        ->assertOk()
        ->assertSee('Recordings')
        ->assertViewHas('recordings', function ($recordings) {
            return $recordings->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('enabled', true);
});

it('creates a new recording', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Welcome Greeting')
        ->set('type', 'greeting')
        ->set('file', UploadedFile::fake()->create('welcome.wav', 8, 'audio/wav'))
        ->call('save')
        ->assertRedirect(route('panel.recordings.index'));

    $this->assertDatabaseHas('recordings', [
        'name' => 'Welcome Greeting',
        'type' => 'greeting',
    ]);

    expect(Recording::withoutGlobalScope('tenant')->where('name', 'Welcome Greeting')->firstOrFail()->mediaAsset)->not->toBeNull();
});

it('updates an existing recording', function () {
    $recording = Recording::factory()->create(['name' => 'Old Music']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsEdit::class, ['recordingId' => $recording->id])
        ->set('name', 'New Hold Music')
        ->set('type', 'moh')
        ->call('save')
        ->assertRedirect(route('panel.recordings.index'));

    $this->assertDatabaseHas('recordings', [
        'id' => $recording->id,
        'name' => 'New Hold Music',
        'type' => 'moh',
    ]);
});

it('deletes a recording', function () {
    $recording = Recording::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsList::class)
        ->call('confirmRecordingDeletion', $recording->id)
        ->assertSet('pendingDeletionId', $recording->id)
        ->call('deleteRecording')
        ->assertSet('operationalMessage', 'Recording deleted.')
        ->assertDispatched('recording-deleted');

    $this->assertModelMissing($recording);
});

it('opens the shared confirmation modal before deleting a recording', function (): void {
    $recording = Recording::factory()->create(['name' => 'Hold music']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsList::class)
        ->call('confirmRecordingDeletion', $recording->id)
        ->assertSet('pendingDeletionId', $recording->id)
        ->assertSet('pendingDeletionName', 'Hold music')
        ->assertSee('Delete Recording?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('only accepts supported audio uploads', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Invalid upload')
        ->set('file', UploadedFile::fake()->create('not-audio.pdf', 8, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['file' => 'mimes']);
});

it('shows empty state when no recordings exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(RecordingsList::class)
        ->assertSee('No recordings found');
});
