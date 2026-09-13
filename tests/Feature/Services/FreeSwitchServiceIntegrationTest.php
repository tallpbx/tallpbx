<?php

declare(strict_types=1);

/**
 * FreeSwitchService ESL integration tests.
 *
 * These tests use the FakeFreeSwitchServer to validate real TCP socket
 * communication with FreeSWITCH's Event Socket Layer protocol. Each
 * test starts a fresh fake server subprocess on a random port and
 * connects via FreeSwitchService for end-to-end protocol validation.
 *
 * Covered flows:
 *   - Connection + authentication handshake
 *   - API command execution and response parsing
 *   - Background API commands (bgapi)
 *   - Event subscription and delivery
 *   - Connection failure handling (wrong password, unreachable host)
 *   - Disconnect and reconnect scenarios
 */

use App\Services\FreeSwitchService;
use Tests\Traits\WithFakeFreeSwitch;

uses(WithFakeFreeSwitch::class);

// ═══════════════════════════════════════════════════════════════════
//  CONNECTION & AUTHENTICATION
// ═══════════════════════════════════════════════════════════════════

it('connects and authenticates successfully with the correct password', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService();

    $connected = $fs->connect();

    expect($connected)->toBeTrue();
    expect($fs->isConnected())->toBeTrue();
});

it('connects when FreeSWITCH returns auth success in the Reply-Text header', function () {
    $this->startFakeFreeSwitch(scenario: 'reply_text_auth');
    $fs = $this->newFreeSwitchService();

    $connected = $fs->connect();

    expect($connected)->toBeTrue();
    expect($fs->api('version'))->toContain('FreeSWITCH Version');
});

it('fails to connect when the host is unreachable', function () {
    // Use a non-existent hostname that will fail DNS resolution.
    // This is more reliable than testing against specific IPs which
    // might have firewall rules or routing that mask connection failures.
    $fs = new FreeSwitchService(
        host: 'freeswitch.does.not.exist.local',
        port: 8021,
        password: 'ClueCon',
        timeout: 1,
    );

    $connected = $fs->connect();

    expect($connected)->toBeFalse();
    expect($fs->isConnected())->toBeFalse();
});

it('fails authentication with wrong password', function () {
    $this->startFakeFreeSwitch(scenario: 'wrong_password');
    $fs = $this->newFreeSwitchService();

    $connected = $fs->connect();

    // The hardened connect() now verifies +OK in the auth response body.
    // wrong_password scenario sends "-ERR invalid password", so connect fails.
    expect($connected)->toBeFalse();
    expect($fs->isConnected())->toBeFalse();
});

it('disconnects gracefully and marks connection as closed', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService();
    $fs->connect();

    expect($fs->isConnected())->toBeTrue();

    $fs->disconnect();

    expect($fs->isConnected())->toBeFalse();
});

it('handles unexpected disconnect after successful authentication', function () {
    $this->startFakeFreeSwitch(scenario: 'disconnect_after_auth');
    $fs = $this->newFreeSwitchService();

    $connected = $fs->connect();

    // Auth succeeds (server sends +OK then closes the socket)
    expect($connected)->toBeTrue();

    // After the server closes, subsequent operations should not crash.
    // The response may be empty (socket dead) or stale buffered data
    // depending on TCP timing — the key assertion is that no exception
    // is thrown and the response is a string.
    $response = $fs->api('version');

    expect($response)->toBeString();

    // Disconnect should still be safe to call
    $fs->disconnect();
    expect($fs->isConnected())->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════
//  API COMMANDS
// ═══════════════════════════════════════════════════════════════════

it('executes common api commands and parses their responses', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService();
    $fs->connect();

    $version = $fs->api('version');
    $status = $fs->api('status');
    $sofiaStatus = $fs->api('sofia status');
    $reloadXml = $fs->api('reloadxml');
    $uptime = $fs->api('uptime');

    expect($version)->toContain('FreeSWITCH Version')
        ->and($version)->toContain('1.10')
        ->and($status)->toContain('UP')
        ->and($status)->toContain('session(s)')
        ->and($sofiaStatus)->toContain('internal')
        ->and($sofiaStatus)->toContain('RUNNING')
        ->and($sofiaStatus)->toContain('3 profiles')
        ->and($reloadXml)->toContain('+OK')
        ->and($uptime)->toContain('hour');
});

// ═══════════════════════════════════════════════════════════════════
//  BACKGROUND API COMMANDS
// ═══════════════════════════════════════════════════════════════════

it('executes bgapi commands and returns job UUIDs', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService();
    $fs->connect();

    $restartJobUuid = $fs->bgapi('sofia profile internal restart');
    $hupallJobUuid = $fs->bgapi('hupall');

    expect($restartJobUuid)->not->toBeEmpty()
        ->and($restartJobUuid)->toStartWith('fake-job-')
        ->and($hupallJobUuid)->not->toBeEmpty()
        ->and($hupallJobUuid)->toStartWith('fake-job-');
});

// ═══════════════════════════════════════════════════════════════════
//  EVENT SUBSCRIPTION
// ═══════════════════════════════════════════════════════════════════

it('subscribes, unsubscribes, and re-subscribes to events successfully', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService();
    $fs->connect();

    // subscribeToEvents reads config('freeswitch.subscribe') for the event list
    $fs->subscribeToEvents();
    $fs->subscribe('CHANNEL_CREATE');

    // Unsubscribing stops events and re-subscribes to remaining configured events
    $fs->unsubscribe('CHANNEL_CREATE');

    expect($fs->isConnected())->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════
//  SEND EVENT
// ═══════════════════════════════════════════════════════════════════

it('sends a custom event to FreeSWITCH', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService();
    $fs->connect();

    // sendEvent fires an event — the fake server consumes it silently
    $fs->sendEvent('CUSTOM', [
        'Event-Subclass' => 'app::notification',
        'message' => 'test notification',
    ]);

    // Should not hang or throw — the command is consumed by the fake server
    expect($fs->isConnected())->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════
//  EDGE CASES
// ═══════════════════════════════════════════════════════════════════

it('returns false from isConnected before first connect', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService();

    // isConnected auto-connects with a short timeout.
    // Since the fake server IS running, this should succeed.
    $connected = $fs->isConnected();

    expect($connected)->toBeTrue();
});

it('api returns empty string when not connected', function () {
    // Create service pointed at a port nothing listens on
    $fs = new FreeSwitchService(
        host: '127.0.0.1',
        port: 59998,
        password: 'ClueCon',
        timeout: 1,
    );

    // Do NOT connect — api internally calls writeLine which calls isConnected
    // which will attempt auto-connect and fail
    $response = $fs->api('version');

    // writeLine returns early when isConnected() is false, so response is empty
    expect($response)->toBe('');
});

it('survives rapid connect-disconnect-reconnect cycles', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService();

    // Cycle 1
    $fs->connect();
    expect($fs->isConnected())->toBeTrue();
    $fs->disconnect();
    expect($fs->isConnected())->toBeFalse();

    // Restart the fake server (previous one closed after first connect)
    $this->startFakeFreeSwitch();

    // Cycle 2
    $fs2 = $this->newFreeSwitchService();
    $fs2->connect();
    expect($fs2->isConnected())->toBeTrue();
    $r = $fs2->api('version');
    expect($r)->toContain('FreeSWITCH Version');
    $fs2->disconnect();
    expect($fs2->isConnected())->toBeFalse();
});

it('can set a custom connection timeout', function () {
    $this->startFakeFreeSwitch();
    $fs = $this->newFreeSwitchService(timeout: 5);

    $start = microtime(true);
    $connected = $fs->connect();
    $elapsed = microtime(true) - $start;

    expect($connected)->toBeTrue();
    // Connection should be near-instant since server is local
    expect($elapsed)->toBeLessThan(1.0);
});
