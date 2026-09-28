<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Transcribe\Livewire\TranscriptionList;
use Modules\Transcribe\Models\Transcription;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    Transcription::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TranscriptionList::class)
        ->assertOk()
        ->assertSee('Transcriptions')
        ->assertViewHas('transcriptions', fn ($items) => $items->count() === 2);
});

it('shows empty state when no transcriptions exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TranscriptionList::class)
        ->assertSee('No transcriptions found');
});

it('deletes a transcription', function () {
    $trans = Transcription::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TranscriptionList::class)
        ->call('confirmTranscriptionDeletion', $trans->id)
        ->assertSet('pendingDeletionId', $trans->id)
        ->call('deleteTranscription')
        ->assertSet('operationalMessage', 'Transcription deleted.')
        ->assertOk();

    expect(Transcription::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting a transcription', function (): void {
    $trans = Transcription::factory()->create(['text' => 'Hello world']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TranscriptionList::class)
        ->call('confirmTranscriptionDeletion', $trans->id)
        ->assertSet('pendingDeletionId', $trans->id)
        ->assertSet('pendingDeletionName', 'Hello world')
        ->assertSee('Delete Transcription?');
});
