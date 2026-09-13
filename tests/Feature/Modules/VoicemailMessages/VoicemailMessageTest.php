<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\VoicemailMessages\Livewire\VoicemailMessagesList;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use Modules\VoicemailMessages\Services\VoicemailMessageService;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the voicemail messages list component', function () {
    VoicemailMessage::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailMessagesList::class)
        ->assertOk()
        ->assertSee('Voicemail Messages')
        ->assertViewHas('messages', function ($messages) {
            return $messages->count() === 2;
        });
});

it('deletes a voicemail message', function () {
    $message = VoicemailMessage::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailMessagesList::class)
        ->call('deleteMessage', $message->id)
        ->assertDispatched('voicemail-message-deleted');

    $this->assertModelMissing($message);
});

it('opens the shared confirmation modal before deleting a voicemail message', function (): void {
    $message = VoicemailMessage::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailMessagesList::class)
        ->call('confirmMessageDeletion', $message->id)
        ->assertSet('pendingDeletionId', $message->id)
        ->assertSee('Delete Voicemail Message?');
});

it('shows empty state when no messages exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailMessagesList::class)
        ->assertSee('No voicemail messages found');
});

it('shows a warning instead of an exception page when FreeSWITCH retains a voicemail', function (): void {
    $message = VoicemailMessage::factory()->create();
    $service = Mockery::mock(VoicemailMessageService::class);
    $service->shouldReceive('delete')->once()->andThrow(new RuntimeException('FreeSWITCH is unavailable; voicemail was retained.'));
    app()->instance(VoicemailMessageService::class, $service);

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailMessagesList::class)
        ->call('deleteMessage', $message->id)
        ->assertSet('operationalMessageType', 'warning')
        ->assertSet('operationalMessage', 'FreeSWITCH is unavailable; voicemail was retained.');
});
