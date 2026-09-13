<?php

declare(strict_types=1);

namespace App\Events\Dashboard;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when dashboard statistics or status need to be refreshed.
 *
 * Implements ShouldBroadcastNow to immediately push a notification over Laravel Reverb
 * WebSockets without waiting for queue processing. Clients listening to the
 * 'dashboard.monitoring' channel will re-query their tenant-scoped statistics.
 */
class DashboardStatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string|null  $source  Optional indicator of what caused the update (e.g. 'freeswitch', 'user', 'tenant')
     */
    public function __construct(
        public ?string $source = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('dashboard.monitoring'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'DashboardStatsUpdated';
    }
}
