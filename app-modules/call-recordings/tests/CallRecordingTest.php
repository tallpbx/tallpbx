<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
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

it('denies tenant users the deletion confirmation for another tenant recording', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = grantTenantUserPermissions($tenantA, ['call-recordings.view', 'call-recordings.delete']);
    $foreignRecording = CallRecording::factory()->create(['tenant_id' => $tenantB->id]);

    // The confirmation fetch is unscoped, so the handler must assert tenant
    // access before the modal can display another tenant's recording.
    Livewire::actingAs($userA, 'web')
        ->test(CallRecordingsList::class)
        ->call('confirmRecordingDeletion', $foreignRecording->id)
        ->assertForbidden();
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
