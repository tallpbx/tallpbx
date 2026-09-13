<?php

declare(strict_types=1);

use App\Events\FreeSwitch\ChannelCreate;
use App\Events\FreeSwitch\ChannelHangup;
use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\Heartbeat;
use App\Events\FreeSwitch\RecordStop;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    config(['freeswitch.esl.reconnect_interval' => 0]);
    config(['freeswitch.esl.host' => '127.0.0.1']);
    config(['freeswitch.esl.port' => 8021]);
});

// ─── Successful Connection and Event Dispatch ───────────────────

it('connects, receives an event with --once, and dispatches it', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(true);
    $mock->shouldReceive('subscribeToEvents')->once();
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'CHANNEL_CREATE',
        'headers' => ['Unique-ID' => 'test-uuid-001', 'Event-Name' => 'CHANNEL_CREATE'],
        'body' => '',
    ]);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(ChannelCreate::class, function (ChannelCreate $e): bool {
        return $e->callUuid() === 'test-uuid-001';
    });
});

it('dispatches known event types to their specific event classes', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(true);
    $mock->shouldReceive('subscribeToEvents')->once();
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'CHANNEL_HANGUP',
        'headers' => ['Unique-ID' => 'hangup-001', 'Event-Name' => 'CHANNEL_HANGUP', 'Hangup-Cause' => 'NORMAL_CLEARING'],
        'body' => '',
    ]);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(ChannelHangup::class, function (ChannelHangup $e): bool {
        return $e->header('Hangup-Cause') === 'NORMAL_CLEARING';
    });
});

it('dispatches unknown event types as CustomEvent', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(true);
    $mock->shouldReceive('subscribeToEvents')->once();
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'SOME_FUTURE_EVENT',
        'headers' => ['Event-Name' => 'SOME_FUTURE_EVENT'],
        'body' => 'custom payload',
    ]);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(CustomEvent::class, function (CustomEvent $e): bool {
        return $e->eventName === 'SOME_FUTURE_EVENT'
            && $e->body === 'custom payload';
    });
});

// ─── Connection Failure ─────────────────────────────────────────

it('fails when initial connection cannot be established', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(false);

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $exitCode = $this->artisan('freeswitch:listen');

    expect($exitCode)->not->toBe(0);

    Event::assertNothingDispatched();
});

// ─── Dispatches heartbeats ──────────────────────────────────────

it('dispatches heartbeat events', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(true);
    $mock->shouldReceive('subscribeToEvents')->once();
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'HEARTBEAT',
        'headers' => ['Event-Name' => 'HEARTBEAT', 'Event-Info' => 'System is alive'],
        'body' => '',
    ]);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(Heartbeat::class, function (Heartbeat $e): bool {
        return $e->header('Event-Info') === 'System is alive';
    });
});

it('dispatches record stop events for completed recording ingestion', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(true);
    $mock->shouldReceive('subscribeToEvents')->once();
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'RECORD_STOP',
        'headers' => ['Unique-ID' => 'recording-001', 'Record-File-Path' => '/managed/recording.wav'],
        'body' => '',
    ]);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(RecordStop::class, function (RecordStop $event): bool {
        return $event->header('Record-File-Path') === '/managed/recording.wav';
    });
});

// ─── Disconnect is called after event processing ────────────────

it('calls disconnect after processing completes', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(true);
    $mock->shouldReceive('subscribeToEvents')->once();
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'HEARTBEAT',
        'headers' => ['Event-Name' => 'HEARTBEAT'],
        'body' => '',
    ]);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();
});
