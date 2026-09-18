<?php

declare(strict_types=1);

namespace Modules\Security\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when an authentication failure or access incident is detected.
 *
 * Broadcasts immediately over Laravel Reverb WebSockets to notify active Security
 * Command Center dashboards in real time.
 */
class SecurityIncidentLogged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $ipAddress  The attacking IP address
     * @param  string  $vector  The attack vector ('sip_auth', 'web_auth', 'ssh')
     * @param  int  $attemptCount  The current failed attempt count in the sliding window
     */
    public function __construct(
        public string $ipAddress,
        public string $vector,
        public int $attemptCount = 1,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('security.alerts'),
        ];
    }

    /**
     * The event's broadcast name for Laravel Echo / Livewire.
     */
    public function broadcastAs(): string
    {
        return 'SecurityIncidentLogged';
    }
}
