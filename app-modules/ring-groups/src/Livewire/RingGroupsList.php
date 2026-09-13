<?php

declare(strict_types=1);

namespace Modules\RingGroups\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\RingGroups\Models\RingGroup;
use Modules\RingGroups\Services\RingGroupServiceInterface;

/**
 * Livewire component for listing ring groups with delete capability.
 *
 * Displays all ring groups ordered by name, showing their strategy,
 * ring timeout, number of extensions, and enabled/disabled status.
 * Supports deleting a ring group (extensions cascade-deleted via FK).
 */
class RingGroupsList extends BaseListComponent
{
    /** @var Collection<int, RingGroup> */
    public Collection $ringGroups;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private RingGroupServiceInterface $ringGroupService;

    /**
     * Inject the ring group service via dependency injection.
     */
    public function boot(RingGroupServiceInterface $ringGroupService): void
    {
        $this->ringGroupService = $ringGroupService;
    }

    /**
     * Load all ring groups on component initialization.
     */
    public function mount(): void
    {
        $this->loadRingGroups();
    }

    /**
     * Fetch all ring groups ordered by name with their extensions loaded.
     */
    private function loadRingGroups(): void
    {
        $this->ringGroups = $this->ringGroupService->getAll();
    }

    /**
     * Delete a ring group. Extensions are cascade-deleted by the database.
     */
    public function deleteRingGroup(string $groupId): void
    {
        $group = RingGroup::withoutGlobalScope('tenant')->findOrFail($groupId);
        $this->ringGroupService->delete($group);
        $this->cancelRingGroupDeletion();
        $this->loadRingGroups();
        $this->showSuccess('Ring group deleted.');
        $this->dispatch('ring-group-deleted');
    }

    /** Open the shared destructive-action confirmation for one ring group. */
    public function confirmRingGroupDeletion(string $groupId): void
    {
        $group = RingGroup::withoutGlobalScope('tenant')->findOrFail($groupId);
        $this->pendingDeletionId = $group->id;
        $this->pendingDeletionName = $group->name;
    }

    /** Close the ring-group deletion confirmation without changing the group. */
    public function cancelRingGroupDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
