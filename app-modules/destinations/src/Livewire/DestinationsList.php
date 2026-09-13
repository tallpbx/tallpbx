<?php

declare(strict_types=1);

namespace Modules\Destinations\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Destinations\Models\Destination;
use Modules\Destinations\Services\DestinationServiceInterface;

/**
 * Livewire component that lists destinations with CRUD actions.
 */
class DestinationsList extends BaseListComponent
{
    /** @var Collection<int, Destination> */
    public Collection $destinations;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private DestinationServiceInterface $destinationService;

    /**
     * Boot the component with the destination service.
     */
    public function boot(DestinationServiceInterface $destinationService): void
    {
        $this->destinationService = $destinationService;
    }

    /**
     * Mount the component and load destinations.
     */
    public function mount(): void
    {
        $this->loadDestinations();
    }

    /**
     * Load all destinations ordered by name.
     */
    private function loadDestinations(): void
    {
        $this->destinations = Destination::withoutGlobalScope('tenant')->orderBy('name')->get();
    }

    /**
     * Delete a destination by its ID.
     */
    public function deleteDestination(string $destinationId): void
    {
        $destination = Destination::withoutGlobalScope('tenant')->findOrFail($destinationId);
        $this->destinationService->delete($destination);
        $this->cancelDestinationDeletion();
        $this->loadDestinations();
        $this->showSuccess('Destination deleted.');
        $this->dispatch('destination-deleted');
    }

    /** Open the shared destructive-action confirmation for one destination. */
    public function confirmDestinationDeletion(string $destinationId): void
    {
        $destination = Destination::withoutGlobalScope('tenant')->findOrFail($destinationId);
        $this->pendingDeletionId = $destination->id;
        $this->pendingDeletionName = $destination->name;
    }

    /** Close the destination deletion confirmation without changing the destination. */
    public function cancelDestinationDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
