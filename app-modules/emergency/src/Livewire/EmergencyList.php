<?php

declare(strict_types=1);

namespace Modules\Emergency\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Emergency\Services\EmergencyServiceInterface;

/**
 * Admin page listing all emergency (E911) configurations.
 *
 * Displays a table of emergency records with caller ID,
 * address, and GPS coordinates. Admins can create, edit,
 * or delete individual emergency configurations.
 */
class EmergencyList extends BaseListComponent
{
    /** @var Collection<int, Emergency> All emergency config records */
    public Collection $records;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    /** The emergency service instance. */
    private EmergencyServiceInterface $service;

    /**
     * Inject the emergency service via Livewire's dependency injection.
     */
    public function boot(EmergencyServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Load all records on component mount.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch all emergency configs ordered by address.
     */
    private function load(): void
    {
        $this->records = $this->service->all();
    }

    /**
     * Delete a single emergency config by its UUID.
     */
    public function deleteRecord(string $id): void
    {
        $record = $this->service->find($id);
        $this->service->delete($record);
        $this->cancelRecordDeletion();
        $this->load();
        $this->showSuccess('Emergency record deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }

    /** Open the shared destructive-action confirmation for one emergency record. */
    public function confirmRecordDeletion(string $id): void
    {
        $record = $this->service->find($id);
        $this->pendingDeletionId = $record->id;
        $this->pendingDeletionName = $record->caller_id ?? $record->address;
    }

    /** Close the emergency-record deletion confirmation without changing the record. */
    public function cancelRecordDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
