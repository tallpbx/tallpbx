<?php

declare(strict_types=1);

use App\Services\FreeSwitchService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Point the configured ESL endpoint at an unreachable port FIRST, so the
    // live-mutation guard tests can match it without ever risking the real
    // switch. The service then targets that same (unreachable) endpoint.
    config()->set('freeswitch.esl.host', '127.0.0.1');
    config()->set('freeswitch.esl.port', 19999);

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

it('decodes url-encoded header values in plain event payloads', function () {
    // mod_event_socket's plain format url-encodes header values on the wire, so
    // a subclass arrives as tallpbx%3A%3Asip_scanner_detected. Without decoding,
    // the subclass comparisons in the listener command never match and every
    // scanner / failed-auth CUSTOM event is silently misclassified.
    $payload = "Event-Name: CUSTOM\n".
        "Event-Subclass: tallpbx%3A%3Asip_scanner_detected\n".
        "Attacker-IP: 127.0.0.1\n".
        "Caller-Caller-ID-Name: John%20Doe%2C%20Jr.\n".
        "Channel-Name: sofia%2Fexternal%2Fscanner%40159.203.57.100\n\n";

    $parsed = $this->service->parseEventPayload($payload);

    expect($parsed['headers']['Event-Subclass'])->toBe('tallpbx::sip_scanner_detected')
        ->and($parsed['headers']['Attacker-IP'])->toBe('127.0.0.1')
        ->and($parsed['headers']['Caller-Caller-ID-Name'])->toBe('John Doe, Jr.')
        ->and($parsed['headers']['Channel-Name'])->toBe('sofia/external/scanner@159.203.57.100');
});

// ─── Live-Mutation Guard During Test Runs ───────────────────────

it('blocks reloadxml against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('reloadxml');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'reloadxml');
});

it('blocks reloadacl against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('reloadacl');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'reloadacl');
});

it('blocks sofia profile restarts against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('sofia profile external restart');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'sofia profile external restart');
});

it('blocks sofia profile rescans against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('sofia profile internal rescan');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'sofia profile internal rescan');
});

it('allows read-only ESL commands during a test run', function () {
    Log::spy();

    $result = $this->service->api('sofia status');

    // The read command must pass the mutation guard and reach the normal
    // connection flow (which fails against the unreachable test port).
    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'FreeSWITCH ESL: api() called without connection.'
            && ($context['command'] ?? null) === 'sofia status');

    Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, 'Blocked FreeSWITCH ESL mutation'));
});

it('blocks originate call creation against the live switch during a test run', function () {
    Log::spy();

    $command = 'originate {ignore_early_media=true}sofia/gateway/1001 5551234 XML default';
    $result = $this->service->api($command);

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === $command);
});

it('blocks hupall call teardown against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('hupall ALLOFTHEM');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'hupall ALLOFTHEM');
});

it('blocks hupall through bgapi as well during a test run', function () {
    Log::spy();

    $result = $this->service->bgapi('hupall');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'hupall');
});

it('blocks uuid channel-control commands against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('uuid_kill abc123');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'uuid_kill abc123');
});

it('blocks conference call-control subcommands against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('conference 3001 kick 1001');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'conference 3001 kick 1001');
});

it('blocks voicemail database mutations against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('vm_fsdb_msg_delete 1001@default 1234');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'vm_fsdb_msg_delete 1001@default 1234');
});

it('blocks module load, unload and reload against the live switch during a test run', function () {
    $commands = ['load mod_sofia', 'unload mod_sofia', 'reload'];

    Log::spy();

    foreach ($commands as $command) {
        expect($this->service->api($command))->toBe('');
    }

    foreach ($commands as $command) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
                && ($context['command'] ?? null) === $command);
    }
});

it('blocks host commands against the live switch during a test run', function () {
    Log::spy();

    $result = $this->service->api('system reboot');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'system reboot');
});

it('allows read-only uuid inspection commands during a test run', function () {
    // Regression guard for the uuid_* family: inspection stays available so
    // monitoring can keep working while call-control forms are blocked.
    Log::spy();

    $result = $this->service->api('uuid_dump abc123');

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'FreeSWITCH ESL: api() called without connection.'
            && ($context['command'] ?? null) === 'uuid_dump abc123');

    Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, 'Blocked FreeSWITCH ESL mutation'));
});

it('allows read-only conference listing during a test run', function () {
    // Regression guard: read-only conference inspection (room-scoped and
    // global forms) must keep flowing to the normal connection path while
    // every mutating subcommand is blocked.
    $commands = ['conference 3001 list', 'conference list', 'conference 3001 count'];

    Log::spy();

    foreach ($commands as $command) {
        expect($this->service->api($command))->toBe('');
    }

    Log::shouldNotHaveReceived('warning', fn (string $message) => str_contains($message, 'Blocked FreeSWITCH ESL mutation'));
});

it('blocks callcenter agent state mutations against the live switch during a test run', function () {
    // The panel's operator controls pause, unpause and log out queue agents
    // through callcenter_config, which changes live queue routing for waiting
    // callers — so the whole family is blocked during test runs.
    Log::spy();

    $command = "callcenter_config agent set status 1001@default 'Available'";
    $result = $this->service->api($command);

    expect($result)->toBe('');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === $command);
});

it('blocks scheduled call mutations and scheduled api commands during a test run', function () {
    // sched_hangup tears down live calls, and sched_api schedules an arbitrary
    // API command for later execution — a complete bypass of every other guard
    // pattern — so the whole sched_* family is blocked.
    $commands = ['sched_hangup +60 abc123', 'sched_api +60 reloadxml'];

    Log::spy();

    foreach ($commands as $command) {
        expect($this->service->api($command))->toBe('');
    }

    foreach ($commands as $command) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
                && ($context['command'] ?? null) === $command);
    }
});

it('blocks sofia global, recover and profile-creation commands during a test run', function () {
    // These mutate switch state above the "sofia profile …" level; a
    // "sofia global restart" can drop every live call.
    $commands = ['sofia global restart', 'sofia recover', 'create_sofia_profile default'];

    Log::spy();

    foreach ($commands as $command) {
        expect($this->service->api($command))->toBe('');
    }

    foreach ($commands as $command) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
                && ($context['command'] ?? null) === $command);
    }
});

it('blocks conference subcommands outside the read-only inspection set', function () {
    // vmute and dial (which creates a call leg) were never in the original
    // call-control enumeration; the guard inverts to a read-only exemption
    // list so unlisted subcommands fail closed.
    $commands = ['conference 3001 vmute 1001', 'conference 3001 dial sofia/gateway/1001 5551234'];

    Log::spy();

    foreach ($commands as $command) {
        expect($this->service->api($command))->toBe('');
    }

    foreach ($commands as $command) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
                && ($context['command'] ?? null) === $command);
    }
});

it('blocks prefix-matched mutations even when internal whitespace is repeated or tabbed', function () {
    // Multi-word prefixes ("sofia profile", "sofia gateway", "sofia global",
    // "sofia recover") must not be bypassed by repeated spaces or tabs.
    $commands = ['sofia  profile internal restart', "sofia\tgateway 1001 kill"];

    Log::spy();

    foreach ($commands as $command) {
        expect($this->service->api($command))->toBe('');
    }

    foreach ($commands as $command) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
                && ($context['command'] ?? null) === $command);
    }
});

it('blocks sendEvent against the live switch during a test run', function () {
    // sendEvent writes a custom ESL frame directly to the socket; against the
    // configured live switch endpoint it must be blocked during test runs.
    Log::spy();

    $this->service->sendEvent('NOTIFY', ['profile' => 'internal']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Blocked FreeSWITCH ESL mutation during a test run'
            && ($context['command'] ?? null) === 'sendevent NOTIFY');
});

it('rejects commands containing newline characters in api()', function () {
    Log::spy();

    expect(fn () => $this->service->api("status\nshutdown"))
        ->toThrow(InvalidArgumentException::class, 'FreeSWITCH ESL command cannot contain newline characters.');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => $message === 'FreeSWITCH ESL: command rejected due to newline injection attempt.'
            && ($context['command'] ?? null) === "status\nshutdown");
});

it('rejects commands containing newline characters in bgapi()', function () {
    Log::spy();

    expect(fn () => $this->service->bgapi("originate ...\r\napi shutdown"))
        ->toThrow(InvalidArgumentException::class, 'FreeSWITCH ESL command cannot contain newline characters.');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => $message === 'FreeSWITCH ESL: command rejected due to newline injection attempt.'
            && ($context['command'] ?? null) === "originate ...\r\napi shutdown");
});
