<?php

declare(strict_types=1);

namespace Modules\VoicemailMessages\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use Modules\VoicemailMessages\Services\VoicemailMessageService;

class VoicemailMessagesList extends BaseListComponent
{
    public ?string $pendingDeletionId = null;

    public ?string $deleteError = null;

    private VoicemailMessageService $messageService;

    public function boot(VoicemailMessageService $messageService): void
    {
        $this->messageService = $messageService;
    }

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

    public function render(): View
    {
        return view('voicemail-messages::voicemail-messages-list', [
            'messages' => VoicemailMessage::withoutGlobalScope('tenant')
                ->with('mediaAsset')
                ->orderBy('created_at', 'desc')
                ->paginate(15),
        ]);
    }
}
