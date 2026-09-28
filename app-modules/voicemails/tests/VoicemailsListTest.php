<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\Voicemails\Livewire\VoicemailsList;
use Modules\Voicemails\Models\Voicemail;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the voicemails list component', function () {
    Voicemail::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsList::class)
        ->assertOk()
        ->assertSee('Voicemails')
        ->assertViewHas('voicemails', function ($voicemails) {
            return $voicemails->count() === 3;
        });
});

it('displays voicemail mailbox and name', function () {
    Voicemail::factory()->create([
        'voicemail_id' => '1000',
        'name' => 'John\'s Voicemail',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsList::class)
        ->assertSee('1000')
        ->assertSee('John\'s Voicemail');
});

it('deletes a voicemail', function () {
    $voicemail = Voicemail::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsList::class)
        ->call('deleteVoicemail', $voicemail->id)
        ->assertDispatched('voicemail-deleted');

    $this->assertModelMissing($voicemail);
});

it('opens the shared confirmation modal before deleting a voicemail mailbox', function (): void {
    $voicemail = Voicemail::factory()->create(['voicemail_id' => '1200']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsList::class)
        ->call('confirmVoicemailDeletion', $voicemail->id)
        ->assertSet('pendingDeletionId', $voicemail->id)
        ->assertSet('pendingDeletionName', '1200')
        ->assertSee('Delete Voicemail Mailbox?');
});

it('shows empty state when no voicemails exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsList::class)
        ->assertSee(__('admin.no_voicemails_found'));
});

it('shows password requirement badge', function () {
    Voicemail::factory()->create([
        'voicemail_id' => '1000',
        'require_password' => true,
    ]);
    Voicemail::factory()->create([
        'voicemail_id' => '1001',
        'require_password' => false,
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(VoicemailsList::class);

    $voicemails = $component->viewData('voicemails');
    $pwRequired = $voicemails->firstWhere('voicemail_id', '1000');
    $pwNotRequired = $voicemails->firstWhere('voicemail_id', '1001');

    expect($pwRequired->require_password)->toBeTrue()
        ->and($pwNotRequired->require_password)->toBeFalse();
});
