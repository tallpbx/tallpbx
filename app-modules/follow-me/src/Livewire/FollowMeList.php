<?php

declare(strict_types=1);

namespace Modules\FollowMe\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\FollowMe\Models\FollowMe;
use Modules\FollowMe\Services\FollowMeService;

/**
 * Livewire component for listing follow-me records.
 *
 * Displays all follow-me records in a table with edit and delete
 * actions. Handles the deletion flow with browser confirmation.
 */
class FollowMeList extends BaseListComponent
{
    /** @var Collection<int, FollowMe> */
    public Collection $followMeRecords;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private FollowMeService $followMeService;

    /**
     * Inject the follow-me service via dependency injection.
     */
    public function boot(FollowMeService $followMeService): void
    {
        $this->followMeService = $followMeService;
    }

    /**
     * Load all follow-me records on component initialization.
     */
    public function mount(): void
    {
        $this->loadRecords();
    }

    /**
     * Fetch all follow-me records ordered by name.
     */
    private function loadRecords(): void
    {
        $this->followMeRecords = FollowMe::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    /**
     * Delete a follow-me record after user confirmation.
     *
     * @param  string  $id  UUID of the follow-me record to delete
     */
    public function deleteFollowMe(string $id): void
    {
        $record = FollowMe::withoutGlobalScope('tenant')->findOrFail($id);
        $this->followMeService->delete($record);
        $this->cancelFollowMeDeletion();
        $this->loadRecords();
        $this->showSuccess('Follow-me rule deleted.');
        $this->dispatch('follow-me-deleted');
    }

    /** Open the shared destructive-action confirmation for one follow-me rule. */
    public function confirmFollowMeDeletion(string $id): void
    {
        $record = FollowMe::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $record->id;
        $this->pendingDeletionName = $record->name;
    }

    /** Close the follow-me deletion confirmation without changing the rule. */
    public function cancelFollowMeDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
