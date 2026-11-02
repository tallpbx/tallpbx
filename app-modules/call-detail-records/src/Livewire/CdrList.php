<?php

declare(strict_types=1);

namespace Modules\XmlCdr\Livewire;

use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\XmlCdr\Models\Cdr;
use Modules\XmlCdr\Services\CdrService;

/**
 * Livewire component listing call detail records with a delete action.
 */
#[Layout('layouts.app')]
class CdrList extends BaseListComponent
{
    /** @var Collection<int, Cdr> */
    public Collection $records;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private CdrService $cdrService;

    /**
     * Inject the CDR service used by this component.
     */
    public function boot(CdrService $cdrService): void
    {
        $this->cdrService = $cdrService;
    }

    /**
     * Load the call detail record list when the page opens.
     */
    public function mount(): void
    {
        $this->loadRecords();
    }

    /**
     * Fetch call detail records newest first. Administrators see every
     * tenant's records; tenant users only see their own.
     */
    private function loadRecords(): void
    {
        $this->records = Cdr::withoutGlobalScope('tenant')
            ->when(! $this->isAdminGuard(), fn ($q) => $q->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->orderBy('start_stamp', 'desc')
            ->get();
    }

    /** Open the shared confirmation modal for a call detail record. */
    public function confirmCdrDeletion(string $id): void
    {
        $cdr = Cdr::withoutGlobalScope('tenant')->findOrFail($id);
        $this->assertCanAccessTenantRecord($cdr);
        $this->pendingDeletionId = $cdr->id;
        // Prefer the FreeSWITCH call UUID; both columns are nullable, so fall back to an empty name.
        $this->pendingDeletionName = $cdr->call_uuid ?? $cdr->destination ?? '';
    }

    /** Close the CDR confirmation modal without deleting anything. */
    public function cancelCdrDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the call detail record that the user confirmed. */
    public function deleteCdr(): void
    {
        $cdr = Cdr::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->assertCanAccessTenantRecord($cdr);
        $this->cdrService->delete($cdr);
        $this->cancelCdrDeletion();
        $this->loadRecords();
        $this->showSuccess('Call detail record deleted.');
        $this->dispatch('cdr-deleted');
    }
}
