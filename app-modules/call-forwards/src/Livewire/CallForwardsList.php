<?php

declare(strict_types=1);

namespace Modules\CallForwards\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallForwards\Models\CallForward;

/**
 * Livewire component for listing call forward rules.
 * Displays all call forward records grouped by extension
 * with their forward type and destination.
 */
class CallForwardsList extends BaseListComponent
{
    /** @var Collection<int, CallForward> */
    public Collection $forwards;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    /**
     * Load all call forward rules on mount.
     */
    public function mount(): void
    {
        $this->loadForwards();
    }

    /**
     * Fetch all call forward rules ordered by forward type.
     */
    private function loadForwards(): void
    {
        $this->forwards = CallForward::withoutGlobalScope('tenant')
            ->orderBy('forward_type')
            ->get();
    }

    /**
     * Delete a call forward rule and refresh the list.
     */
    public function deleteForward(string $forwardId): void
    {
        $forward = CallForward::withoutGlobalScope('tenant')->findOrFail($forwardId);
        $forward->delete();
        $this->cancelForwardDeletion();
        $this->loadForwards();
        $this->showSuccess('Call forward deleted.');
        $this->dispatch('forward-deleted');
    }

    /** Open the shared destructive-action confirmation for one call forward. */
    public function confirmForwardDeletion(string $forwardId): void
    {
        $forward = CallForward::withoutGlobalScope('tenant')->findOrFail($forwardId);
        $this->pendingDeletionId = $forward->id;
        $this->pendingDeletionName = $forward->destination;
    }

    /** Close the call-forward deletion confirmation without changing the rule. */
    public function cancelForwardDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
