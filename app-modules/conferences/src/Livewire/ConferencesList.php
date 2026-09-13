<?php

declare(strict_types=1);

namespace Modules\Conferences\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Conferences\Models\Conference;
use Modules\Conferences\Services\ConferenceService;

/**
 * Livewire component for listing conference rooms.
 *
 * Displays all conferences in a table with edit and delete actions.
 * Handles the deletion flow with browser confirmation.
 */
class ConferencesList extends BaseListComponent
{
    /** @var Collection<int, Conference> */
    public Collection $conferences;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private ConferenceService $conferenceService;

    /**
     * Inject the conference service via dependency injection.
     */
    public function boot(ConferenceService $conferenceService): void
    {
        $this->conferenceService = $conferenceService;
    }

    /**
     * Load all conferences on component initialization.
     */
    public function mount(): void
    {
        $this->loadConferences();
    }

    /**
     * Fetch all conferences ordered by name.
     */
    private function loadConferences(): void
    {
        $this->conferences = Conference::withoutGlobalScope('tenant')
            ->with('tenant')
            ->orderBy('name')
            ->get();
    }

    /**
     * Delete a conference after user confirmation.
     *
     * @param  string  $id  UUID of the conference to delete
     */
    public function deleteConference(string $id): void
    {
        $conference = Conference::withoutGlobalScope('tenant')->findOrFail($id);
        $this->conferenceService->delete($conference);
        $this->cancelConferenceDeletion();
        $this->loadConferences();
        $this->showSuccess('Conference deleted.');
        $this->dispatch('conference-deleted');
    }

    /** Open the shared destructive-action confirmation for one conference. */
    public function confirmConferenceDeletion(string $id): void
    {
        $conference = Conference::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $conference->id;
        $this->pendingDeletionName = $conference->name;
    }

    /** Close the conference deletion confirmation without changing the conference. */
    public function cancelConferenceDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
