<?php

declare(strict_types=1);

namespace Modules\Fax\Livewire;

use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Fax\Models\FaxInbox as FaxInboxModel;
use Modules\Fax\Services\FaxServiceInterface;

#[Layout('layouts.app')]
class FaxInbox extends Component
{
    use HasOperationalFeedback;

    /** @var Collection<int, FaxInboxModel> */
    public Collection $faxes;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    private FaxServiceInterface $faxService;

    public function boot(FaxServiceInterface $faxService): void
    {
        $this->faxService = $faxService;
    }

    public function mount(): void
    {
        $this->loadFaxes();
    }

    private function loadFaxes(): void
    {
        $this->faxes = FaxInboxModel::withoutGlobalScope('tenant')
            ->with('mediaAsset')
            ->orderBy('received_at', 'desc')
            ->get();
    }

    /** Open the shared confirmation modal for a received fax. */
    public function confirmFaxDeletion(string $id): void
    {
        $fax = FaxInboxModel::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $fax->id;
        // Caller ID is nullable for received faxes, so keep the modal name safe.
        $this->pendingDeletionName = $fax->caller_id ?? '';
        $this->deleteError = null;
    }

    /** Close the fax confirmation modal and discard any expected error message. */
    public function cancelFaxDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName', 'deleteError');
    }

    /** Delete the received fax that the user confirmed. */
    public function deleteFax(): void
    {
        $fax = FaxInboxModel::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        try {
            $this->faxService->deleteInbox($fax);
        } catch (\RuntimeException $exception) {
            $this->deleteError = 'Fax could not be deleted. Please try again.';

            return;
        }

        $this->cancelFaxDeletion();
        $this->loadFaxes();
        $this->showSuccess('Fax deleted.');
        $this->dispatch('fax-deleted');
    }

    public function render(): View
    {
        return view('fax::fax-inbox');
    }
}
