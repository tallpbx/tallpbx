<?php

declare(strict_types=1);

namespace Modules\EmailQueue\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\EmailQueue\Models\EmailQueueItem;
use Modules\EmailQueue\Services\EmailQueueService;

/**
 * Admin page that displays the outbound email queue.
 *
 * Shows a read-only table of queued, sent, and failed emails
 * with their delivery status. Admins can delete individual items
 * from the queue.
 */
class EmailQueueList extends BaseListComponent
{
    /** @var Collection<int, EmailQueueItem> All queued email items */
    public Collection $items;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    /** The email queue service instance. */
    private EmailQueueService $queueService;

    /**
     * Inject the email queue service.
     */
    public function boot(EmailQueueService $queueService): void
    {
        $this->queueService = $queueService;
    }

    /**
     * Load all queue items on component initialization.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch all queue items ordered by creation date descending.
     */
    private function load(): void
    {
        $this->items = EmailQueueItem::withoutGlobalScope('tenant')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /** Open the shared confirmation modal for a queued email. */
    public function confirmItemDeletion(string $id): void
    {
        $item = EmailQueueItem::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $item->id;
        $this->pendingDeletionName = $item->subject;
    }

    /** Close the queued-email confirmation modal without deleting anything. */
    public function cancelItemDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the queued email that the user confirmed. */
    public function deleteItem(): void
    {
        $item = EmailQueueItem::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->queueService->delete($item);
        $this->cancelItemDeletion();
        $this->load();
        $this->showSuccess('Email deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }

    /**
     * Render the email queue list view.
     */
}
