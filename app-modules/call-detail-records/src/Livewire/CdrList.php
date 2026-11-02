<?php

declare(strict_types=1);

namespace Modules\CallDetailRecords\Livewire;

use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallDetailRecords\Models\Cdr;
use Modules\CallDetailRecords\Services\CdrService;

/**
 * Livewire component listing call detail records with a delete action.
 */
#[Layout('layouts.app')]
class CdrList extends BaseListComponent
{
    /** Rows loaded per page; "load more" adds another page. */
    private const PAGE_SIZE = 25;

    /** @var Collection<int, Cdr> */
    public Collection $records;

    /** Which call side to show: all, a, or b. */
    public string $legFilter = 'all';

    /** How many rows are currently on screen. */
    public int $limit = self::PAGE_SIZE;

    /** Whether older records exist beyond the current window. */
    public bool $hasMore = false;

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
     * Reload the list and reset the window when the leg filter changes.
     */
    public function updatedLegFilter(): void
    {
        $this->limit = self::PAGE_SIZE;
        $this->loadRecords();
    }

    /**
     * Show another page of older records.
     */
    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
        $this->loadRecords();
    }

    /**
     * Fetch call detail records newest first. Administrators see every
     * tenant's records; tenant users only see their own.
     */
    private function loadRecords(): void
    {
        // One extra row tells us whether a further page exists without a
        // second COUNT query over a table the worker keeps growing.
        $rows = Cdr::withoutGlobalScope('tenant')
            ->when(! $this->isAdminGuard(), fn ($q) => $q->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->when($this->legFilter !== 'all', fn ($q) => $q->where('leg', $this->legFilter))
            ->orderBy('start_stamp', 'desc')
            ->limit($this->limit + 1)
            ->get();

        $this->hasMore = $rows->count() > $this->limit;
        $this->records = $rows->take($this->limit);
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
