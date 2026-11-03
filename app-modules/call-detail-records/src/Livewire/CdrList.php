<?php

declare(strict_types=1);

namespace Modules\CallDetailRecords\Livewire;

use App\Services\TenantManager;
use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallDetailRecords\Models\Cdr;
use Modules\CallDetailRecords\Services\CdrService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Livewire component listing call detail records with a delete action.
 */
#[Layout('layouts.app')]
class CdrList extends BaseListComponent
{
    /** Rows loaded per page; "load more" adds another page. */
    private const PAGE_SIZE = 25;

    /** Column headings written on the first line of the exported CSV. */
    private const EXPORT_COLUMNS = [
        'Date',
        'Leg',
        'Caller ID',
        'Caller Name',
        'Destination',
        'Direction',
        'Duration (seconds)',
        'Billsec',
        'Hangup Cause',
        'Call UUID',
        'Linked Leg UUID',
    ];

    /**
     * Maximum rows written to one exported file.
     *
     * A busy PBX accumulates records quickly, so the export is bounded. The
     * bound keeps one request from running long enough to exhaust the
     * appliance's memory or PHP's execution limit.
     */
    private const EXPORT_ROW_LIMIT = 25000;

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
     * Map the export action onto the module's export permission.
     *
     * Without this override the shared resolver would treat an unknown action
     * as a plain interaction and require only the view permission — and a CSV
     * file leaves the panel, so it must demand its own grant.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function livewireActionAbilities(): array
    {
        return [
            'exportCdrs' => [['call-detail-records.export']],
        ];
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
     * Download the records the viewer can see as a CSV file.
     *
     * The file holds exactly what the list shows: the same tenant scope and
     * the same leg filter, so an export can never disclose a record the
     * list would hide. Rows are streamed page by page, so a large export
     * never loads the whole table into memory.
     */
    public function exportCdrs(): StreamedResponse
    {
        $query = $this->scopedQuery();
        $total = (clone $query)->count();

        if ($total > self::EXPORT_ROW_LIMIT) {
            $this->showWarning(__('admin.cdr_export_truncated', [
                'exported' => self::EXPORT_ROW_LIMIT,
                'total' => $total,
            ]));
        }

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, self::EXPORT_COLUMNS);

            $written = 0;

            foreach ($query->lazy(1000) as $record) {
                if ($written >= self::EXPORT_ROW_LIMIT) {
                    break;
                }

                fputcsv($handle, [
                    $record->start_stamp?->toIso8601String() ?? '',
                    strtoupper((string) $record->leg),
                    $this->csvValue($record->caller_id),
                    $this->csvValue($record->caller_id_name),
                    $this->csvValue($record->destination),
                    $this->csvValue($record->direction),
                    (int) $record->duration,
                    (int) $record->billsec,
                    $this->csvValue($record->hangup_cause),
                    $this->csvValue($record->call_uuid),
                    $this->csvValue($record->b_leg_uuid),
                ]);

                $written++;
            }

            fclose($handle);
        }, 'call-detail-records-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Fetch call detail records newest first. Administrators see every
     * tenant's records; tenant users only see their own.
     */
    private function loadRecords(): void
    {
        // One extra row tells us whether a further page exists without a
        // second COUNT query over a table the worker keeps growing.
        $rows = $this->scopedQuery()
            ->limit($this->limit + 1)
            ->get();

        $this->hasMore = $rows->count() > $this->limit;
        $this->records = $rows->take($this->limit);
    }

    /**
     * Build the record query shared by the list and the CSV export.
     *
     * Both paths must agree on tenant scope and the leg filter; if they
     * drifted, an export could hand a tenant records its own list hides.
     *
     * @return Builder<Cdr>
     */
    private function scopedQuery(): Builder
    {
        return Cdr::withoutGlobalScope('tenant')
            ->when(! $this->isAdminGuard(), fn ($q) => $q->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->when($this->legFilter !== 'all', fn ($q) => $q->where('leg', $this->legFilter))
            // The id is the tiebreaker so the order is total: two calls that
            // started in the same second still have a fixed order, which the
            // paged export depends on.
            ->orderBy('start_stamp', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * Prepare one value for a spreadsheet cell.
     *
     * A spreadsheet treats a cell beginning with = + - @ as a formula and
     * runs it when the file is opened. Caller-supplied text such as a display
     * name can carry such a payload, so the leading character is defused with
     * an apostrophe, which the spreadsheet reads as "this cell is plain
     * text". The apostrophe is not displayed by the spreadsheet; a plain text
     * editor shows it.
     */
    private function csvValue(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '=')
            || str_starts_with($value, '+')
            || str_starts_with($value, '-')
            || str_starts_with($value, '@')
            || str_starts_with($value, "\t")
            || str_starts_with($value, "\r")) {
            return "'".$value;
        }

        return $value;
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
