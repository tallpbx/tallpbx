<?php

declare(strict_types=1);

namespace Modules\VoicemailMessages\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use Modules\VoicemailMessages\Services\VoicemailMessageService;

/**
 * Livewire component listing voicemail messages with a delete action.
 */
class VoicemailMessagesList extends BaseListComponent
{
    public ?string $pendingDeletionId = null;

    public ?string $deleteError = null;

    private VoicemailMessageService $messageService;

    /**
     * Inject the voicemail message service used by this component.
     */
    public function boot(VoicemailMessageService $messageService): void
    {
        $this->messageService = $messageService;
    }

    /**
     * Delete a voicemail message, keeping any failure message visible
     * for the user.
     */
    public function deleteMessage(string $id): void
    {
        $message = VoicemailMessage::withoutGlobalScope('tenant')->findOrFail($id);
        try {
            $this->messageService->delete($message);
        } catch (\RuntimeException $exception) {
            $this->deleteError = $exception->getMessage();

            if ($this->pendingDeletionId === null) {
                $this->showWarning($this->deleteError);
            }

            return;
        }

        $this->cancelMessageDeletion();
        $this->showSuccess('Voicemail message deleted.');
        $this->dispatch('voicemail-message-deleted');
    }

    /** Open the shared destructive-action confirmation for one voicemail message. */
    public function confirmMessageDeletion(string $id): void
    {
        $this->pendingDeletionId = VoicemailMessage::withoutGlobalScope('tenant')->findOrFail($id)->id;
        $this->deleteError = null;
    }

    /** Close the voicemail deletion confirmation without changing the message. */
    public function cancelMessageDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->deleteError = null;
    }

    /**
     * Render the paginated voicemail message list. Administrators see
     * every tenant's messages; tenant users only see their own.
     */
    public function render(): View
    {
        $query = $this->isAdminGuard()
            ? VoicemailMessage::withoutGlobalScope('tenant')
            : VoicemailMessage::query();

        return view('voicemail-messages::voicemail-messages-list', [
            'messages' => $query
                ->with('mediaAsset')
                ->orderBy('created_at', 'desc')
                ->paginate(15),
        ]);
    }
}
