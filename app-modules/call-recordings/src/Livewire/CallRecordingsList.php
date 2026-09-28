<?php

declare(strict_types=1);

namespace Modules\CallRecordings\Livewire;

use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallRecordings\Models\CallRecording;
use Modules\CallRecordings\Services\CallRecordingService;

/**
 * Livewire component listing call recordings with a delete action.
 */
class CallRecordingsList extends BaseListComponent
{
    /** @var Collection<int, CallRecording> */
    public Collection $recordings;

    public ?string $pendingDeletionId = null;

    public ?string $deleteError = null;

    private CallRecordingService $recordingService;

    /**
     * Inject the call recording service used by this component.
     */
    public function boot(CallRecordingService $recordingService): void
    {
        $this->recordingService = $recordingService;
    }

    /**
     * Load the call recording list when the page opens.
     */
    public function mount(): void
    {
        $this->loadRecordings();
    }

    /**
     * Fetch call recordings newest first. Administrators see every
     * tenant's recordings; tenant users only see their own.
     */
    private function loadRecordings(): void
    {
        $this->recordings = CallRecording::withoutGlobalScope('tenant')
            ->with('mediaAsset')
            ->when(! $this->isAdminGuard(), fn ($q) => $q->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Delete the confirmed recording and refresh the list, keeping any
     * failure message visible for the user.
     */
    public function deleteRecording(string $id): void
    {
        $recording = CallRecording::withoutGlobalScope('tenant')->findOrFail($id);
        try {
            $this->recordingService->delete($recording);
        } catch (\RuntimeException $exception) {
            $this->deleteError = 'Recording could not be deleted. '.$exception->getMessage();

            if ($this->pendingDeletionId === null) {
                $this->showError($this->deleteError);
            }

            return;
        }

        $this->cancelRecordingDeletion();
        $this->loadRecordings();
        $this->showSuccess('Call recording deleted.');
        $this->dispatch('call-recording-deleted');
    }

    /** Open the shared destructive-action confirmation for one call recording. */
    public function confirmRecordingDeletion(string $id): void
    {
        $this->pendingDeletionId = CallRecording::withoutGlobalScope('tenant')->findOrFail($id)->id;
        $this->deleteError = null;
    }

    /** Close the recording deletion confirmation without changing the recording. */
    public function cancelRecordingDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->deleteError = null;
    }
}
