<?php

declare(strict_types=1);

use App\Events\FreeSwitch\CallCenterEvent;
use App\Events\FreeSwitch\ChannelAnswer;
use App\Events\FreeSwitch\ChannelBridge;
use App\Events\FreeSwitch\ChannelCreate;
use App\Events\FreeSwitch\ChannelDestroy;
use App\Events\FreeSwitch\ChannelHangup;
use App\Events\FreeSwitch\ChannelHangupComplete;
use App\Events\FreeSwitch\ChannelUnbridge;
use App\Events\FreeSwitch\ConferenceEvent;
use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\Dtmf;
use App\Events\FreeSwitch\FreeSwitchEvent;
use App\Events\FreeSwitch\Heartbeat;
use App\Events\FreeSwitch\RecordStart;
use App\Events\FreeSwitch\RecordStop;
use App\Events\FreeSwitch\SofiaExpire;
use App\Events\FreeSwitch\SofiaRegister;

// ─── Base Event Properties ──────────────────────────────────────

it('stores event name, headers, and body', function () {
    $event = new ChannelCreate(
        eventName: 'CHANNEL_CREATE',
        headers: ['Unique-ID' => 'call-uuid-123', 'Caller-Caller-ID-Number' => '1001'],
        body: '<event-body/>',
    );

    expect($event->eventName)->toBe('CHANNEL_CREATE')
        ->and($event->headers)->toHaveKey('Unique-ID', 'call-uuid-123')
        ->and($event->body)->toBe('<event-body/>');
});

// ─── header() Helper ────────────────────────────────────────────

it('retrieves a header by key', function () {
    $event = new ChannelHangup(
        eventName: 'CHANNEL_HANGUP',
        headers: ['Hangup-Cause' => 'NORMAL_CLEARING'],
        body: '',
    );

    expect($event->header('Hangup-Cause'))->toBe('NORMAL_CLEARING');
});

it('returns null for missing header', function () {
    $event = new ChannelHangup(
        eventName: 'CHANNEL_HANGUP',
        headers: [],
        body: '',
    );

    expect($event->header('Nonexistent'))->toBeNull();
});

it('returns default value for missing header', function () {
    $event = new ChannelHangup(
        eventName: 'CHANNEL_HANGUP',
        headers: [],
        body: '',
    );

    expect($event->header('Nonexistent', 'fallback'))->toBe('fallback');
});

// ─── callUuid() Resolution ──────────────────────────────────────

it('resolves call uuid from Unique-ID header', function () {
    $event = new ChannelAnswer(
        eventName: 'CHANNEL_ANSWER',
        headers: ['Unique-ID' => 'abc-123-def'],
        body: '',
    );

    expect($event->callUuid())->toBe('abc-123-def');
});

it('resolves call uuid from Caller-Unique-ID when Unique-ID is missing', function () {
    $event = new ChannelBridge(
        eventName: 'CHANNEL_BRIDGE',
        headers: ['Caller-Unique-ID' => 'bridge-uuid-456'],
        body: '',
    );

    expect($event->callUuid())->toBe('bridge-uuid-456');
});

it('resolves call uuid from Channel-Call-UUID as last fallback', function () {
    $event = new Dtmf(
        eventName: 'DTMF',
        headers: ['Channel-Call-UUID' => 'channel-uuid-789'],
        body: '',
    );

    expect($event->callUuid())->toBe('channel-uuid-789');
});

it('prefers Unique-ID over other uuid headers', function () {
    $event = new CustomEvent(
        eventName: 'CUSTOM',
        headers: [
            'Unique-ID' => 'primary-uuid',
            'Caller-Unique-ID' => 'secondary-uuid',
            'Channel-Call-UUID' => 'tertiary-uuid',
        ],
        body: '',
    );

    expect($event->callUuid())->toBe('primary-uuid');
});

it('returns null when no uuid headers are present', function () {
    $event = new Heartbeat(
        eventName: 'HEARTBEAT',
        headers: ['Event-Info' => 'System is alive'],
        body: '',
    );

    expect($event->callUuid())->toBeNull();
});

// ─── All Event Types Instantiate Correctly ──────────────────────

it('all concrete event classes extend FreeSwitchEvent', function (
    string $eventClass,
) {
    $event = new $eventClass(
        eventName: strtoupper(class_basename($eventClass)),
        headers: [],
        body: '',
    );

    expect($event)->toBeInstanceOf(FreeSwitchEvent::class);
})->with([
    CallCenterEvent::class,
    ChannelCreate::class,
    ChannelAnswer::class,
    ChannelHangup::class,
    ChannelHangupComplete::class,
    ChannelDestroy::class,
    ChannelBridge::class,
    ChannelUnbridge::class,
    ConferenceEvent::class,
    Dtmf::class,
    RecordStart::class,
    RecordStop::class,
    CustomEvent::class,
    Heartbeat::class,
    SofiaExpire::class,
    SofiaRegister::class,
]);

// ─── Dispatchable Trait ─────────────────────────────────────────

it('has Dispatchable trait available for static dispatch', function () {
    $traits = class_uses(FreeSwitchEvent::class);

    expect($traits)->toHaveKey('Illuminate\Foundation\Events\Dispatchable');
});
