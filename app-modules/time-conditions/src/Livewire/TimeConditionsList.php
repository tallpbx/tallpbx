<?php

declare(strict_types=1);

namespace Modules\TimeConditions\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\TimeConditions\Models\TimeCondition;
use Modules\TimeConditions\Services\TimeConditionService;

/**
 * Livewire component for listing time conditions.
 *
 * Displays all time conditions in a table with edit and delete
 * actions. Handles the deletion flow with browser confirmation.
 */
class TimeConditionsList extends BaseListComponent
{
    /** @var Collection<int, TimeCondition> */
    public Collection $timeConditions;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private TimeConditionService $timeConditionService;

    /**
     * Inject the time condition service via dependency injection.
     */
    public function boot(TimeConditionService $timeConditionService): void
    {
        $this->timeConditionService = $timeConditionService;
    }

    /**
     * Load all time conditions on component initialization.
     */
    public function mount(): void
    {
        $this->loadTimeConditions();
    }

    /**
     * Fetch all time conditions ordered by name.
     */
    private function loadTimeConditions(): void
    {
        $this->timeConditions = TimeCondition::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    /**
     * Delete a time condition after user confirmation.
     *
     * @param  string  $id  UUID of the time condition to delete
     */
    public function deleteTimeCondition(string $id): void
    {
        $condition = TimeCondition::withoutGlobalScope('tenant')->findOrFail($id);
        $this->timeConditionService->delete($condition);
        $this->cancelTimeConditionDeletion();
        $this->loadTimeConditions();
        $this->showSuccess('Time condition deleted.');
        $this->dispatch('time-condition-deleted');
    }

    /** Open the shared destructive-action confirmation for one time condition. */
    public function confirmTimeConditionDeletion(string $id): void
    {
        $condition = TimeCondition::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $condition->id;
        $this->pendingDeletionName = $condition->name;
    }

    /** Close the time-condition deletion confirmation without changing the condition. */
    public function cancelTimeConditionDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
