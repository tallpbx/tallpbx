<?php

declare(strict_types=1);

namespace Modules\ConferenceCenters\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\ConferenceCenters\Services\ConferenceCenterService;

/**
 * Livewire component for listing conference centers.
 *
 * Displays all conference centers in a table with edit and delete
 * actions. Handles the deletion flow with browser confirmation.
 */
class ConferenceCentersList extends BaseListComponent
{
    /** @var Collection<int, ConferenceCenter> */
    public Collection $conferenceCenters;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private ConferenceCenterService $centerService;

    /**
     * Inject the conference center service via dependency injection.
     */
    public function boot(ConferenceCenterService $centerService): void
    {
        $this->centerService = $centerService;
    }

    /**
     * Load all conference centers on component initialization.
     */
    public function mount(): void
    {
        $this->loadCenters();
    }

    /**
     * Fetch all conference centers ordered by name.
     */
    private function loadCenters(): void
    {
        $this->conferenceCenters = ConferenceCenter::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    /** Open the shared confirmation modal for a conference center. */
    public function confirmConferenceCenterDeletion(string $id): void
    {
        $center = ConferenceCenter::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $center->id;
        $this->pendingDeletionName = $center->name;
    }

    /** Close the conference-center confirmation modal without deleting anything. */
    public function cancelConferenceCenterDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the conference center that the user confirmed. */
    public function deleteConferenceCenter(): void
    {
        $center = ConferenceCenter::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->centerService->delete($center);
        $this->cancelConferenceCenterDeletion();
        $this->loadCenters();
        $this->showSuccess('Conference center deleted.');
        $this->dispatch('conference-center-deleted');
    }
}
