<?php

declare(strict_types=1);

namespace Modules\Security\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when Observe Mode packet traffic is logged or received.
 *
 * Broadcasts immediately over Laravel Reverb WebSockets on the private security.alerts
 * channel so active Security Center browser sessions reactively update their observe
 * counters and event tables without polling or manual page reload.
 */
class ObserveTrafficLogged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>|null  $event  Optional parsed observe event data
     */
    public function __construct(
        public ?array $event = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('security.alerts'),
        ];
    }

    /**
     * The event's broadcast name for Laravel Echo / Livewire.
     */
    public function broadcastAs(): string
    {
        return 'ObserveTrafficLogged';
    }
}
