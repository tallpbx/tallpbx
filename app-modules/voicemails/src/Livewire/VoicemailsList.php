<?php

declare(strict_types=1);

namespace Modules\Voicemails\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Voicemails\Models\Voicemail;
use Modules\Voicemails\Services\VoicemailServiceInterface;
use RuntimeException;

/**
 * Livewire component that lists voicemail mailboxes with CRUD actions.
 */
class VoicemailsList extends BaseListComponent
{
    /** @var Collection<int, Voicemail> */
    public Collection $voicemails;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    private VoicemailServiceInterface $voicemailService;

    /**
     * Boot the component with the voicemail service.
     */
    public function boot(VoicemailServiceInterface $voicemailService): void
    {
        $this->voicemailService = $voicemailService;
    }

    /**
     * Mount the component and load voicemail mailboxes.
     */
    public function mount(): void
    {
        $this->loadVoicemails();
    }

    /**
     * Load all voicemail mailboxes ordered by voicemail ID.
     */
    private function loadVoicemails(): void
    {
        $this->voicemails = Voicemail::withoutGlobalScope('tenant')->orderBy('voicemail_id')->get();
    }

    /**
     * Delete a voicemail mailbox by its ID.
     */
    public function deleteVoicemail(string $voicemailId): void
    {
        $voicemail = Voicemail::withoutGlobalScope('tenant')->findOrFail($voicemailId);

        try {
            $this->voicemailService->delete($voicemail);
        } catch (RuntimeException $exception) {
            $this->deleteError = $exception->getMessage();

            return;
        }

        $this->cancelVoicemailDeletion();
        $this->loadVoicemails();
        $this->showSuccess('Voicemail mailbox deleted.');
        $this->dispatch('voicemail-deleted');
    }

    /** Open the shared destructive-action confirmation for one voicemail mailbox. */
    public function confirmVoicemailDeletion(string $voicemailId): void
    {
        $voicemail = Voicemail::withoutGlobalScope('tenant')->findOrFail($voicemailId);
        $this->pendingDeletionId = $voicemail->id;
        $this->pendingDeletionName = $voicemail->voicemail_id;
        $this->deleteError = null;
    }

    /** Close the voicemail deletion confirmation without changing the mailbox. */
    public function cancelVoicemailDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
        $this->deleteError = null;
    }
}
