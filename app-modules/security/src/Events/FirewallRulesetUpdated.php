<?php

declare(strict_types=1);

namespace Modules\Security\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when the host firewall ruleset or port configuration is modified.
 *
 * Broadcasts immediately over Laravel Reverb WebSockets so any open Security Center
 * browser sessions update their rules and pending change counts without refreshing.
 */
class FirewallRulesetUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string|null  $source  Optional description of the trigger source
     */
    public function __construct(
        public ?string $source = null,
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
        return 'FirewallRulesetUpdated';
    }
}
