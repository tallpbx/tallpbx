<?php

declare(strict_types=1);

namespace Modules\Recordings\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Modules\Recordings\Models\Recording;
use Modules\Recordings\Services\RecordingService;

class RecordingsList extends BaseListComponent
{
    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private RecordingService $recordingService;

    public function boot(RecordingService $recordingService): void
    {
        $this->recordingService = $recordingService;
    }

    /** Open the shared confirmation modal for a recording. */
    public function confirmRecordingDeletion(string $id): void
    {
        $recording = Recording::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $recording->id;
        $this->pendingDeletionName = $recording->name;
    }

    /** Close the recording confirmation modal without deleting anything. */
    public function cancelRecordingDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the recording that the user confirmed. */
    public function deleteRecording(): void
    {
        $recording = Recording::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->recordingService->delete($recording);
        $this->cancelRecordingDeletion();
        $this->showSuccess('Recording deleted.');
        $this->dispatch('recording-deleted');
    }

    public function render(): View
    {
        return view('recordings::recordings-list', [
            'recordings' => Recording::withoutGlobalScope('tenant')
                ->orderBy('name')
                ->paginate(15),
        ]);
    }
}
