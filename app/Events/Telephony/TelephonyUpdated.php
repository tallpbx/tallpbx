<?php

declare(strict_types=1);

namespace App\Events\Telephony;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when real-time telephony status changes in FreeSWITCH.
 *
 * Implements ShouldBroadcastNow to immediately push updates over Laravel Reverb / Echo
 * without queue delay. Subscribers include runtime status views: active calls,
 * active conferences, SIP registrations, SIP profile statuses, call center queues/agents,
 * and the operator panel.
 */
class TelephonyUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new TelephonyUpdated event instance.
     *
     * @param  string|null  $tenantId  Tenant ID if the event is tenant-scoped, or null/'global' for system-wide events.
     * @param  string|null  $eventName  FreeSWITCH event name (e.g., 'CHANNEL_CREATE', 'SOFIA_REGISTER').
     * @param  string|null  $callUuid  Call UUID if applicable.
     */
    public function __construct(
        public readonly ?string $tenantId = null,
        public readonly ?string $eventName = null,
        public readonly ?string $callUuid = null,
        public readonly array $data = [],
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('tenant.global.telephony'),
        ];

        if ($this->tenantId !== null && $this->tenantId !== '' && $this->tenantId !== 'global') {
            $channels[] = new PrivateChannel("tenant.{$this->tenantId}.telephony");
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'TelephonyUpdated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'event_name' => $this->eventName,
            'call_uuid' => $this->callUuid,
            'data' => $this->data,
            'timestamp' => now()->timestamp,
        ];
    }
}
