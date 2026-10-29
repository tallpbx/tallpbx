<?php

declare(strict_types=1);

namespace Modules\Fax\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Modules\Fax\Models\FaxInbox as FaxInboxModel;
use Modules\Fax\Services\FaxServiceInterface;

/**
 * Livewire component listing received faxes with a delete action.
 */
#[Layout('layouts.app')]
class FaxInbox extends BaseListComponent
{
    use WithPagination;

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
     * Fetch received faxes newest first. Administrators see every
     * tenant's faxes; tenant users only see their own.
     */
    #[Computed]
    public function faxes(): LengthAwarePaginator
    {
        $query = $this->isAdminGuard()
            ? FaxInboxModel::withoutGlobalScope('tenant')
            : FaxInboxModel::query();

        return $query
            ->with('mediaAsset')
            ->orderBy('received_at', 'desc')
            ->paginate(25);
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
        $this->showSuccess('Fax deleted.');
        $this->dispatch('fax-deleted');
    }

    /**
     * Render the fax inbox view.
     */
    public function render(): View
    {
        return view('fax::fax-inbox', [
            'faxes' => $this->faxes,
        ]);
    }
}
