<?php

declare(strict_types=1);

namespace Modules\Fax\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Modules\Fax\Models\FaxInbox as FaxInboxModel;
use Modules\Fax\Services\FaxServiceInterface;

/**
 * Livewire component listing received faxes with a delete action.
 */
#[Layout('layouts.app')]
class FaxInbox extends BaseListComponent
{
    /** @var Collection<int, FaxInboxModel> */
    public Collection $faxes;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    private FaxServiceInterface $faxService;

    /**
     * Inject the fax service used by this component.
     */
    public function boot(FaxServiceInterface $faxService): void
    {
        $this->faxService = $faxService;
    }

    /**
     * Load the received fax list when the page opens.
     */
    public function mount(): void
    {
        $this->loadFaxes();
    }

    /**
     * Fetch received faxes newest first. Administrators see every
     * tenant's faxes; tenant users only see their own.
     */
    private function loadFaxes(): void
    {
        $query = $this->isAdminGuard()
            ? FaxInboxModel::withoutGlobalScope('tenant')
            : FaxInboxModel::query();

        $this->faxes = $query
            ->with('mediaAsset')
            ->orderBy('received_at', 'desc')
            ->get();
    }

    /** Open the shared confirmation modal for a received fax. */
    public function confirmFaxDeletion(string $id): void
    {
        $fax = FaxInboxModel::withoutGlobalScope('tenant')->findOrFail($id);
        $this->assertCanAccessTenantRecord($fax);
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
        $this->assertCanAccessTenantRecord($fax);
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

    /**
     * Render the fax inbox view.
     */
    public function render(): View
    {
        return view('fax::fax-inbox');
    }
}
