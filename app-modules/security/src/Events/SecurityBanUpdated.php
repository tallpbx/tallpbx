<?php

declare(strict_types=1);

namespace Modules\Security\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when an IP address is banned, unbanned, or modified.
 *
 * Implements ShouldBroadcastNow to immediately push the update over Laravel Reverb
 * WebSockets to the 'security.alerts' channel without queue latency.
 */
class SecurityBanUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string|null  $ipAddress  The IP address that was banned or unbanned
     * @param  string  $action  The action taken ('ban', 'unban', 'promote_whitelist', 'promote_blacklist')
     */
    public function __construct(
        public ?string $ipAddress = null,
        public string $action = 'updated',
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
        return 'SecurityBanUpdated';
    }
}
