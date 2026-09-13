<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Contract for the FreeSWITCH Event Socket Layer (ESL) service.
 *
 * Provides a persistent connection to the FreeSWITCH ESL interface,
 * event subscription management, command execution, and background
 * job support. All ESL communication flows through this service.
 */
interface FreeSwitchServiceInterface
{
    /**
     * Establish a persistent ESL socket connection.
     *
     * Returns true if the connection was established successfully,
     * false otherwise. After a successful connection, the service
     * authenticates and subscribes to configured event types.
     */
    public function connect(?int $timeout = null): bool;

    /**
     * Disconnect from the ESL socket.
     *
     * Gracefully closes the ESL connection and releases
     * the socket resource. Safe to call even if not connected.
     */
    public function disconnect(): void;

    /**
     * Check whether the ESL socket is currently connected.
     */
    public function isConnected(): bool;

    /**
     * Execute a FreeSWITCH API command and return the response.
     *
     * @param  string  $command  The FreeSWITCH API command (e.g., 'sofia status')
     * @return string The raw response body from FreeSWITCH
     */
    public function api(string $command): string;

    /**
     * Execute a background API command (non-blocking).
     *
     * Returns a job UUID that can be used to track the result.
     *
     * @param  string  $command  The FreeSWITCH API command
     * @return string The background job UUID
     */
    public function bgapi(string $command): string;

    /**
     * Send an event to FreeSWITCH.
     *
     * @param  string  $eventName  The event type to fire
     * @param  array<string, string>  $headers  Event headers as key-value pairs
     * @param  string  $body  Optional event body content
     */
    public function sendEvent(string $eventName, array $headers = [], string $body = ''): void;

    /**
     * Read the next event from the ESL socket.
     *
     * Blocks until an event is received, then returns the parsed
     * event data as an associative array with 'headers' and 'body'.
     *
     * @return array{event_name: string, headers: array<string, string>, body: string}|null
     */
    public function recvEvent(): ?array;

    /**
     * Subscribe to a FreeSWITCH event type.
     *
     * @param  string  $eventType  The event type (e.g., 'CHANNEL_HANGUP')
     */
    public function subscribe(string $eventType): void;

    /**
     * Subscribe to all configured FreeSWITCH event types.
     *
     * Called by the listener command after connecting. Web requests
     * that only need API access should not call this — it switches
     * the socket into event-streaming mode.
     */
    public function subscribeToEvents(): void;

    /**
     * Unsubscribe from a FreeSWITCH event type.
     *
     * @param  string  $eventType  The event type to unsubscribe
     */
    public function unsubscribe(string $eventType): void;
}
