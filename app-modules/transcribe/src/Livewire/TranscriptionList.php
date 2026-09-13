<?php

declare(strict_types=1);

namespace Modules\Transcribe\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Modules\Transcribe\Services\TranscribeServiceInterface;

/**
 * Admin page displaying speech-to-text transcription results.
 *
 * Shows a read-only table of all transcriptions linked to
 * voicemail messages, with the transcribed text, confidence
 * score, and language. Admins can delete individual entries.
 */
#[Layout('layouts.app')]
class TranscriptionList extends BaseListComponent
{
    /** @var Collection<int, Transcription> All transcription records */
    public Collection $transcriptions;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    /** The transcription service instance. */
    private TranscribeServiceInterface $service;

    /**
     * Inject the transcription service via Livewire's dependency injection.
     */
    public function boot(TranscribeServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Load all transcriptions on component mount.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch all transcriptions ordered by newest first.
     */
    private function load(): void
    {
        $this->transcriptions = $this->service->all();
    }

    /** Open the shared confirmation modal for a transcription. */
    public function confirmTranscriptionDeletion(string $id): void
    {
        $transcription = $this->service->find($id);
        $this->pendingDeletionId = $transcription->id;
        $this->pendingDeletionName = str($transcription->text)->limit(80)->toString();
    }

    /** Close the transcription confirmation modal without deleting anything. */
    public function cancelTranscriptionDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the transcription that the user confirmed. */
    public function deleteTranscription(): void
    {
        $trans = $this->service->find($this->pendingDeletionId);
        $this->service->delete($trans);
        $this->cancelTranscriptionDeletion();
        $this->load();
        $this->showSuccess('Transcription deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }

    /**
     * Render the transcription list view.
     */
}
