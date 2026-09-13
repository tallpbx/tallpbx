<?php

declare(strict_types=1);

namespace App\Events\FreeSwitch;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base event dispatched when a FreeSWITCH ESL event is received.
 *
 * All FreeSWITCH-originated events extend this class, providing
 * consistent access to event headers and body content.
 */
abstract class FreeSwitchEvent
{
    use Dispatchable;

    /**
     * Create a new FreeSWITCH event instance.
     *
     * @param  string  $eventName  The FreeSWITCH event type (e.g., 'CHANNEL_HANGUP')
     * @param  array<string, string>  $headers  Event headers parsed from ESL
     * @param  string  $body  Raw event body content
     */
    public function __construct(
        public readonly string $eventName,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /**
     * Get a specific header value with an optional default.
     */
    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[$key] ?? $default;
    }

    /**
     * Get the call UUID associated with this event, if any.
     */
    public function callUuid(): ?string
    {
        return $this->header('Unique-ID')
            ?? $this->header('Caller-Unique-ID')
            ?? $this->header('Channel-Call-UUID');
    }
}
