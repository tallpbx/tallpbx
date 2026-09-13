<?php

declare(strict_types=1);

use App\Services\FreeSwitchService;

beforeEach(function () {
    // Use an unreachable port so auto-connect fails fast (1-second timeout)
    $this->service = new FreeSwitchService(
        host: '127.0.0.1',
        port: 19999,
        password: 'ClueCon',
        timeout: 1,
    );
});

// ─── Initial State ──────────────────────────────────────────────

it('is not connected after construction because auto-connect fails to unreachable port', function () {
    expect($this->service->isConnected())->toBeFalse();
});

it('returns null from recvEvent when not connected', function () {
    expect($this->service->recvEvent())->toBeNull();
});

// ─── Constructor ────────────────────────────────────────────────

it('accepts all constructor parameters', function () {
    $service = new FreeSwitchService(
        host: '10.0.0.5',
        port: 19999,
        password: 'secret',
        timeout: 1,
    );

    expect($service)->toBeInstanceOf(FreeSwitchService::class)
        ->and($service->isConnected())->toBeFalse();
});

// ─── Disconnect Safety ──────────────────────────────────────────

it('disconnects safely when not connected', function () {
    $this->service->disconnect();

    expect($this->service->isConnected())->toBeFalse();
});

it('disconnect sets internal state to null', function () {
    $this->service->disconnect();

    expect($this->service->isConnected())->toBeFalse();
});

// ─── Subscribe/Unsubscribe Safety ───────────────────────────────

it('subscribe does not throw when not connected', function () {
    $this->service->subscribe('CHANNEL_HANGUP');

    expect($this->service->isConnected())->toBeFalse();
});

it('unsubscribe does not throw when not connected', function () {
    $this->service->unsubscribe('CHANNEL_CREATE');

    expect($this->service->isConnected())->toBeFalse();
});

// ─── Framed Plain Event Payload Parsing ────────────────────────────

it('parses event headers from the body of a content-length framed plain event', function () {
    $payload = "Event-Name: CHANNEL_CREATE\n".
        "Core-UUID: core-123\n".
        "Channel-Name: null/1000\n".
        "Unique-ID: abc-123\n\n";

    $parsed = $this->service->parseEventPayload($payload);

    expect($parsed['event_name'])->toBe('CHANNEL_CREATE')
        ->and($parsed['headers']['Core-UUID'])->toBe('core-123')
        ->and($parsed['headers']['Channel-Name'])->toBe('null/1000')
        ->and($parsed['headers']['Unique-ID'])->toBe('abc-123')
        ->and($parsed['body'])->toBe('');
});

it('keeps a trailing event body separate from its parsed headers', function () {
    $payload = "Event-Name: CUSTOM\n".
        "Event-Subclass: test::event\n\n".
        "payload-line-1\npayload-line-2";

    $parsed = $this->service->parseEventPayload($payload);

    expect($parsed['event_name'])->toBe('CUSTOM')
        ->and($parsed['headers']['Event-Subclass'])->toBe('test::event')
        ->and($parsed['body'])->toBe("payload-line-1\npayload-line-2");
});
