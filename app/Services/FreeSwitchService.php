<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FreeSWITCH Event Socket Layer (ESL) service implementation.
 *
 * Manages a persistent TCP socket connection to the FreeSWITCH ESL
 * interface. Handles authentication, event subscriptions, API command
 * execution, and event parsing using the ESL text protocol.
 *
 * The ESL protocol is line-oriented with the following flow:
 *
 *   1. Connect to the TCP socket
 *   2. Receive an auth/request header
 *   3. Send "auth <password>" and receive a response
 *   4. Send "event plain <event-types>" to subscribe
 *   5. Read events as they arrive (Content-Length delimited)
 *
 * ESL command format:
 *   send <command>\n<content>
 *   sendmsg <uuid>\n<header>: <value>\n...
 *
 * ESL response format:
 *   Content-Type: text/event-plain
 *   Content-Length: <bytes>
 *
 *   <raw event body>
 */
class FreeSwitchService implements FreeSwitchServiceInterface
{
    /**
     * The ESL socket resource, or null when not connected.
     */
    private mixed $socket = null;

    /**
     * Safely log a message, degrading gracefully when the Log facade
     * or application container is unavailable (e.g., unit tests creating
     * the service directly outside the Laravel bootstrap).
     */
    private function logMessage(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (Throwable) {
            // Log or container not available — service created outside the container
        }
    }

    /**
     * Create a new FreeSwitchService instance.
     *
     * Connection details are read from the freeswitch.esl config.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $password,
        private readonly int $timeout,
    ) {}

    /**
     * Establish a persistent ESL socket connection.
     *
     * Connects to the configured FreeSWITCH ESL host:port,
     * authenticates with the configured password, and verifies
     * the +OK authentication result before reporting success.
     *
     * @param  int|null  $timeout  Override the configured timeout (seconds).
     *                             Used by isConnected() auto-probe for fast failure.
     */
    public function connect(?int $timeout = null): bool
    {
        // Direct socket check to avoid recursion with isConnected()
        if ($this->socket !== null && is_resource($this->socket) && ! feof($this->socket)) {
            return true;
        }

        $connectTimeout = $timeout ?? $this->timeout;

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $connectTimeout);

        if (! $socket) {
            $this->logMessage('error', "FreeSWITCH ESL connection failed: [{$errno}] {$errstr}", [
                'host' => $this->host,
                'port' => $this->port,
            ]);

            return false;
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, $this->timeout);

        $this->socket = $socket;

        // Step 1: Read the auth/request header FreeSWITCH sends on connect
        $authHeader = $this->readUntilBlankLine();

        if ($authHeader === null || ! str_contains($authHeader, 'auth/request')) {
            $this->logMessage('error', 'FreeSWITCH ESL: unexpected auth header.', [
                'received' => $authHeader,
            ]);
            $this->disconnect();

            return false;
        }

        // Step 2: Authenticate — send "auth <password>"
        $this->writeLine("auth {$this->password}");

        // Step 3: Read the command/reply headers from the auth response.
        $authResponse = $this->readUntilBlankLine();

        if ($authResponse === null || ! str_contains($authResponse, 'Content-Type: command/reply')) {
            $this->logMessage('error', 'FreeSWITCH ESL authentication failed.', [
                'response' => $authResponse,
            ]);
            $this->disconnect();

            return false;
        }

        // Real FreeSWITCH returns the auth result as a Reply-Text header.
        // Some tests and ESL examples return it as a body line after the
        // blank separator, so support both protocol shapes.
        $authResult = $this->headerValue($authResponse, 'Reply-Text') ?? $this->readLine();

        if ($authResult === null || ! str_starts_with($authResult, '+OK')) {
            $this->logMessage('error', 'FreeSWITCH ESL authentication rejected.', [
                'response' => $authResult,
            ]);
            $this->disconnect();

            return false;
        }

        $this->logMessage('info', 'FreeSWITCH ESL connected and authenticated.', [
            'host' => $this->host,
            'port' => $this->port,
        ]);

        return true;
    }

    /**
     * Subscribe to FreeSWITCH events on the ESL socket.
     *
     * Called by the listener command after connecting. Web requests
     * that only need API access do not need to subscribe to events.
     */
    public function subscribeToEvents(): void
    {
        if (! $this->isConnected()) {
            return;
        }

        $eventTypes = config('freeswitch.subscribe', []);
        $eventList = implode(' ', $eventTypes);
        $this->writeLine("event plain {$eventList}");

        // Consume the subscription response
        $this->readUntilBlankLine();

        $this->logMessage('info', 'FreeSWITCH ESL subscribed to events.', [
            'events' => $eventTypes,
        ]);
    }

    /**
     * Disconnect from the ESL socket.
     *
     * Gracefully closes the socket connection and marks
     * the internal state as disconnected.
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /**
     * Check whether the ESL socket is currently connected.
     *
     * If no socket exists yet, attempts to auto-connect.
     */
    public function isConnected(): bool
    {
        if ($this->socket !== null && is_resource($this->socket) && ! feof($this->socket)) {
            return true;
        }

        // Auto-connect on first call — use a short timeout so the page
        // doesn't hang when FreeSWITCH is not running (1 second vs 10).
        return $this->connect(timeout: 1);
    }

    /**
     * Execute a FreeSWITCH API command and return the response.
     *
     * Sends "api <command>" to FreeSWITCH and collects the response
     * body. The response is delimited by Content-Length headers.
     *
     * Returns an empty string when the socket is not connected
     * (e.g., FreeSWITCH is unreachable or the connection was lost).
     *
     * @param  string  $command  The FreeSWITCH API command
     * @return string The raw response body from FreeSWITCH, or empty string on failure
     */
    public function api(string $command): string
    {
        if (! $this->isConnected()) {
            $this->logMessage('warning', 'FreeSWITCH ESL: api() called without connection.', [
                'command' => $command,
            ]);

            return '';
        }

        $this->writeLine("api {$command}");

        return $this->readResponse();
    }

    /**
     * Execute a background API command (non-blocking).
     *
     * Sends "bgapi <command>" and returns the job UUID for tracking.
     * Returns an empty string when not connected or when the response
     * does not contain a valid Job-UUID.
     *
     * @param  string  $command  The FreeSWITCH API command
     * @return string The background job UUID, or empty string on failure
     */
    public function bgapi(string $command): string
    {
        if (! $this->isConnected()) {
            $this->logMessage('warning', 'FreeSWITCH ESL: bgapi() called without connection.', [
                'command' => $command,
            ]);

            return '';
        }

        $this->writeLine("bgapi {$command}");

        $response = $this->readResponse();

        // Parse the job UUID from the response body
        // Format: "+OK Job-UUID: <uuid>"
        if (preg_match('/Job-UUID:\s*(\S+)/', $response, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Send an event to FreeSWITCH.
     *
     * Fires a custom event that can be caught in the FreeSWITCH
     * dialplan or by other ESL clients.
     *
     * @param  string  $eventName  The event type to fire
     * @param  array<string, string>  $headers  Event headers
     * @param  string  $body  Optional event body
     */
    public function sendEvent(string $eventName, array $headers = [], string $body = ''): void
    {
        $headerLines = implode("\n", array_map(
            fn (string $key, string $value): string => "{$key}: {$value}",
            array_keys($headers),
            $headers,
        ));

        $message = "sendevent {$eventName}\n{$headerLines}";

        if ($body !== '') {
            $message .= "\n\n{$body}";
        }

        $this->writeLine($message);
    }

    /**
     * Read the next event from the ESL socket.
     *
     * Blocking read that waits for the next event. Parses
     * Content-Length-delimited events and returns structured data.
     *
     * @return array{event_name: string, headers: array<string, string>, body: string}|null
     */
    public function recvEvent(): ?array
    {
        if (! $this->isConnected()) {
            return null;
        }

        $line = $this->readLine();

        if ($line === null || $line === '') {
            return null;
        }

        $headers = [];
        $eventName = '';

        // Parse headers until blank line
        while ($line !== '' && $line !== null) {
            if (str_contains($line, ': ')) {
                [$key, $value] = explode(': ', $line, 2);
                $headers[$key] = $value;

                if ($key === 'Event-Name') {
                    $eventName = $value;
                }
            }
            $line = $this->readLine();
        }

        // Content-Length header tells us how many bytes of body to read
        $contentLength = isset($headers['Content-Length'])
            ? (int) $headers['Content-Length']
            : 0;

        $body = '';
        if ($contentLength > 0) {
            $body = $this->readBytes($contentLength);
        }

        // FreeSWITCH frames every ESL message — including "event plain"
        // events — with Content-Type/Content-Length headers and puts the real
        // event (Event-Name and event headers) in the body. Without this
        // merge, every event would dispatch with an empty name and no
        // listener would ever run.
        if ($eventName === '' && $body !== '') {
            $parsed = $this->parseEventPayload($body);
            $eventName = $parsed['event_name'];
            $headers = $parsed['headers'] + $headers;
            $body = $parsed['body'];
        }

        return [
            'event_name' => $eventName,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * Parse the payload of a content-length framed ESL event message.
     *
     * The payload starts with the event headers (Event-Name first), then an
     * optional blank line and the event body. Returns the event name, the
     * parsed headers, and any payload left after the header block.
     *
     * @return array{event_name: string, headers: array<string, string>, body: string}
     */
    public function parseEventPayload(string $payload): array
    {
        $eventName = '';
        $headers = [];
        $body = '';

        [$headerBlock, $remaining] = array_pad(explode("\n\n", $payload, 2), 2, '');

        foreach (explode("\n", $headerBlock) as $line) {
            if (str_contains($line, ': ')) {
                [$key, $value] = explode(': ', $line, 2);
                $headers[$key] = $value;

                if ($key === 'Event-Name') {
                    $eventName = $value;
                }
            }
        }

        return [
            'event_name' => $eventName,
            'headers' => $headers,
            'body' => $remaining,
        ];
    }

    /**
     * Subscribe to a FreeSWITCH event type.
     *
     * Sends an "event plain <type>" subscription command
     * for the given event type.
     *
     * @param  string  $eventType  The event type (e.g., 'CHANNEL_HANGUP')
     */
    public function subscribe(string $eventType): void
    {
        if ($this->isConnected()) {
            $this->writeLine("event plain {$eventType}");
        }
    }

    /**
     * Unsubscribe from a FreeSWITCH event type.
     *
     * Sends a "noevents" command to stop receiving events,
     * then re-subscribes to all currently configured event types
     * except the one being removed.
     *
     * @param  string  $eventType  The event type to unsubscribe
     */
    public function unsubscribe(string $eventType): void
    {
        if (! $this->isConnected()) {
            return;
        }

        $currentEvents = config('freeswitch.subscribe', []);
        $filtered = array_filter($currentEvents, fn (string $e): bool => $e !== $eventType);

        $this->writeLine('noevents');

        if ($filtered !== []) {
            $eventList = implode(' ', $filtered);
            $this->writeLine("event plain {$eventList}");
        }
    }

    // ────────────────────────────────────────────────────────────
    //  Internal ESL protocol helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Write a line to the ESL socket.
     *
     * Appends a newline after the message.
     */
    private function writeLine(string $line): void
    {
        if (! $this->isConnected()) {
            return;
        }

        fwrite($this->socket, $line."\n\n");
    }

    /**
     * Read a single line from the ESL socket.
     *
     * Returns null if the socket read times out or the
     * connection is closed.
     */
    private function readLine(): ?string
    {
        if (! $this->isConnected()) {
            return null;
        }

        $line = fgets($this->socket, 8192);

        if ($line === false || $line === '') {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Read a specific number of bytes from the ESL socket.
     */
    private function readBytes(int $length): string
    {
        if (! $this->isConnected() || $length <= 0) {
            return '';
        }

        $buffer = '';
        $remaining = $length;

        while ($remaining > 0 && ! feof($this->socket)) {
            $chunk = fread($this->socket, min($remaining, 8192));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }

    /**
     * Read lines until a blank line is encountered.
     *
     * Used to consume multi-line ESL response headers.
     * Returns the concatenated header block.
     */
    private function readUntilBlankLine(): ?string
    {
        $lines = [];

        while (true) {
            $line = $this->readLine();

            if ($line === null) {
                break;
            }

            if ($line === '') {
                break;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Return one header value from an ESL header block.
     *
     * Header names are matched case-insensitively because ESL response
     * examples and real FreeSWITCH builds may differ in capitalization.
     */
    private function headerValue(string $headers, string $name): ?string
    {
        foreach (explode("\n", $headers) as $headerLine) {
            if (! str_contains($headerLine, ':')) {
                continue;
            }

            [$headerName, $headerValue] = explode(':', $headerLine, 2);

            if (strcasecmp(trim($headerName), $name) === 0) {
                return trim($headerValue);
            }
        }

        return null;
    }

    /**
     * Read a Content-Length-delimited ESL response.
     *
     * Reads headers until a blank line, then reads the body
     * based on Content-Length.
     */
    private function readResponse(): string
    {
        $headers = $this->readUntilBlankLine();

        $contentLength = 0;

        if ($headers !== null) {
            foreach (explode("\n", $headers) as $headerLine) {
                if (str_starts_with($headerLine, 'Content-Length: ')) {
                    $contentLength = (int) substr($headerLine, 16);
                    break;
                }
            }
        }

        if ($contentLength > 0) {
            return $this->readBytes($contentLength);
        }

        return $headers ?? '';
    }

    /**
     * Clean up the ESL socket on destruction.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
