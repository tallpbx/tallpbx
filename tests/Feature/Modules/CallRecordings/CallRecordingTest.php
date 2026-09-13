<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\CallRecordings\Livewire\CallRecordingsList;
use Modules\CallRecordings\Models\CallRecording;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the call recordings list component', function () {
    CallRecording::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallRecordingsList::class)
        ->assertOk()
        ->assertSee('Call Recordings')
        ->assertViewHas('recordings', function ($recordings) {
            return $recordings->count() === 2;
        });
});

it('deletes a call recording', function () {
    $recording = CallRecording::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallRecordingsList::class)
        ->call('deleteRecording', $recording->id)
        ->assertDispatched('call-recording-deleted');

    $this->assertModelMissing($recording);
});

it('opens the shared confirmation modal before deleting a recording', function (): void {
    $recording = CallRecording::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallRecordingsList::class)
        ->call('confirmRecordingDeletion', $recording->id)
        ->assertSet('pendingDeletionId', $recording->id)
        ->assertSee('Delete Recording?');
});

it('shows empty state when no recordings exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallRecordingsList::class)
        ->assertSee('No call recordings found');
});
