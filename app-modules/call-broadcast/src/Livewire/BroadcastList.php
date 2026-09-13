<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Modules\CallBroadcast\Jobs\SendCallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Services\CallBroadcastService;

#[Layout('layouts.app')]
class BroadcastList extends BaseListComponent
{
    /** @var Collection<int, CallBroadcast> */
    public Collection $broadcasts;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    /** The broadcast id awaiting send confirmation. */
    public ?string $sendingId = null;

    /** The broadcast name shown in the send confirmation modal. */
    public ?string $sendingName = null;

    private CallBroadcastService $broadcastService;

    public function boot(CallBroadcastService $broadcastService): void
    {
        $this->broadcastService = $broadcastService;
    }

    public function mount(): void
    {
        $this->loadBroadcasts();
    }

    private function loadBroadcasts(): void
    {
        $this->broadcasts = CallBroadcast::withoutGlobalScope('tenant')
            ->withCount([
                'recipients',
                'recipients as answered_count' => fn ($query) => $query->where('call_status', 'answered'),
                'recipients as failed_count' => fn ($query) => $query->where('call_status', 'failed'),
            ])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /** Open the shared confirmation modal for a call broadcast. */
    public function confirmBroadcastDeletion(string $id): void
    {
        $broadcast = CallBroadcast::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $broadcast->id;
        $this->pendingDeletionName = $broadcast->name;
    }

    /** Close the call-broadcast confirmation modal without deleting anything. */
    public function cancelBroadcastDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the call broadcast that the user confirmed. */
    public function deleteBroadcast(): void
    {
        $broadcast = CallBroadcast::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->broadcastService->delete($broadcast);
        $this->cancelBroadcastDeletion();
        $this->loadBroadcasts();
        $this->showSuccess('Call broadcast deleted.');
        $this->dispatch('broadcast-deleted');
    }

    /** Open the send confirmation modal for a draft broadcast. */
    public function confirmSend(string $id): void
    {
        $broadcast = CallBroadcast::withoutGlobalScope('tenant')->findOrFail($id);
        $this->sendingId = $broadcast->id;
        $this->sendingName = $broadcast->name;
    }

    /** Close the send confirmation modal without sending. */
    public function cancelSend(): void
    {
        $this->reset('sendingId', 'sendingName');
    }

    /** Mark the broadcast sending and queue the originate job. */
    public function sendBroadcast(): void
    {
        $broadcast = CallBroadcast::withoutGlobalScope('tenant')->findOrFail($this->sendingId);

        // Atomic claim: only a draft may flip to sending, so concurrent
        // sends cannot both win (the update is the race guard).
        $claimed = CallBroadcast::withoutGlobalScope('tenant')
            ->whereKey($broadcast->id)
            ->where('status', 'draft')
            ->update(['status' => 'sending']);

        if ($claimed === 0) {
            $this->reset('sendingId', 'sendingName');

            return;
        }

        Queue::push(new SendCallBroadcast($broadcast->id));
        $this->reset('sendingId', 'sendingName');
        $this->loadBroadcasts();
    }
}
