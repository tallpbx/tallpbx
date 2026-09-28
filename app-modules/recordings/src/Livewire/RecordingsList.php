<?php

declare(strict_types=1);

namespace Modules\Recordings\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Modules\Recordings\Models\Recording;
use Modules\Recordings\Services\RecordingService;

/**
 * Livewire component listing recordings with a delete action.
 */
class RecordingsList extends BaseListComponent
{
    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private RecordingService $recordingService;

    /**
     * Inject the recording service used by this component.
     */
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

    /**
     * Render the paginated recordings list. Administrators see every
     * tenant's recordings; tenant users only see their own.
     */
    public function render(): View
    {
        $query = $this->isAdminGuard()
            ? Recording::withoutGlobalScope('tenant')
            : Recording::query();

        return view('recordings::recordings-list', [
            'recordings' => $query
                ->orderBy('name')
                ->paginate(15),
        ]);
    }
}
