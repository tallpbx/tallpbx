<?php

declare(strict_types=1);

namespace Modules\CallCenters\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\CallCenters\Models\Queue;
use Modules\CallCenters\Services\CallCenterServiceInterface;

#[Layout('layouts.app')]
class QueueList extends BaseListComponent
{
    /** @var Collection<int, Queue> */
    public Collection $queues;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private CallCenterServiceInterface $service;

    /** Inject the call-center service. */
    public function boot(CallCenterServiceInterface $service): void
    {
        $this->service = $service;
    }

    /** Load the queue list when the component starts. */
    public function mount(): void
    {
        $this->load();
    }

    /** Fetch all call-center queues ordered by name. */
    private function load(): void
    {
        $query = $this->isAdminGuard()
            ? Queue::withoutGlobalScope('tenant')
            : Queue::query();

        $this->queues = $query->orderBy('name')->get();
    }

    /** Delete the confirmed queue and refresh the list. */
    public function deleteQueue(string $id): void
    {
        $q = Queue::withoutGlobalScope('tenant')->findOrFail($id);
        $this->service->deleteQueue($q);
        $this->cancelQueueDeletion();
        $this->load();
        $this->showSuccess('Queue deleted.');
        $this->dispatch('queue-deleted');
    }

    /** Open the shared destructive-action confirmation for one queue. */
    public function confirmQueueDeletion(string $id): void
    {
        $queue = Queue::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $queue->id;
        $this->pendingDeletionName = $queue->name;
    }

    /** Close the queue deletion confirmation without changing the queue. */
    public function cancelQueueDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
