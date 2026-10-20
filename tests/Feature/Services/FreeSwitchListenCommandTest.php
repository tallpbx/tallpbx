<?php

declare(strict_types=1);

use App\Console\Commands\FreeSwitchListenCommand;
use App\Events\FreeSwitch\ChannelCreate;
use App\Events\FreeSwitch\ChannelHangup;
use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\Heartbeat;
use App\Events\FreeSwitch\RecordStop;
use App\Listeners\BroadcastDashboardStatsOnFreeSwitchEvent;
use App\Listeners\BroadcastTelephonyOnFreeSwitchEvent;
use App\Services\FreeSwitchService;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\CallBroadcast\Listeners\MarkBroadcastRecipientOutcome;
use Modules\CallRecordings\Listeners\RegisterCompletedCallRecording;
use Modules\Security\Listeners\LogFailedSipAuthListener;
use Modules\Security\Listeners\LogSipScannerListener;
use Modules\VoicemailMessages\Listeners\RegisterCompletedVoicemailMessage;

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

it('does not debug-log dispatched heartbeat events', function () {
    Log::spy();

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

    $this->artisan('freeswitch:listen', ['--once' => true])->assertSuccessful();

    // Heartbeat keepalives are dispatched and broadcast but never debug-logged.
    Log::shouldNotHaveReceived('debug');
});

it('still debug-logs dispatched non-heartbeat events', function () {
    Log::spy();

    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(true);
    $mock->shouldReceive('subscribeToEvents')->once();
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'CHANNEL_CREATE',
        'headers' => ['Unique-ID' => 'test-uuid-log-001', 'Event-Name' => 'CHANNEL_CREATE'],
        'body' => '',
    ]);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $this->artisan('freeswitch:listen', ['--once' => true])->assertSuccessful();

    Log::shouldHaveReceived('debug')
        ->withArgs(fn (string $message): bool => $message === 'FreeSWITCH event dispatched.')
        ->once();
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

// ─── Event Ingestion Buffer & Queue Tests ───────────────────────

it('drains pending socket events into bounded buffer when hasPendingData is true', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->andReturn(true);
    $mock->shouldReceive('subscribeToEvents')->once();
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')
        ->once()
        ->andReturn([
            'event_name' => 'CHANNEL_CREATE',
            'headers' => ['Unique-ID' => 'uuid-1', 'Event-Name' => 'CHANNEL_CREATE'],
            'body' => '',
        ]);
    $mock->shouldReceive('hasPendingData')->once()->andReturn(true);
    $mock->shouldReceive('recvEvent')
        ->once()
        ->andReturn([
            'event_name' => 'CHANNEL_ANSWER',
            'headers' => ['Unique-ID' => 'uuid-1', 'Event-Name' => 'CHANNEL_ANSWER'],
            'body' => '',
        ]);
    $mock->shouldReceive('hasPendingData')->once()->andReturn(false);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(FreeSwitchServiceInterface::class, $mock);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();

    Event::assertDispatched(ChannelCreate::class);
});

it('drops oldest event when in-memory buffer exceeds maximum capacity', function () {
    $command = new FreeSwitchListenCommand;

    // Use reflection to initialize command buffer with capacity 2
    $ref = new ReflectionClass($command);
    $propBuffer = $ref->getProperty('buffer');
    $propBuffer->setValue($command, new SplQueue);

    $propMax = $ref->getProperty('maxBufferSize');
    $propMax->setValue($command, 2);

    $command->enqueueEvent(['event_name' => 'FIRST_EVENT', 'headers' => [], 'body' => '']);
    $command->enqueueEvent(['event_name' => 'SECOND_EVENT', 'headers' => [], 'body' => '']);

    expect($command->getBufferSize())->toBe(2);
    expect($command->getDroppedEventsCount())->toBe(0);

    // Enqueue 3rd event: capacity is 2, so FIRST_EVENT must be dropped
    $command->enqueueEvent(['event_name' => 'THIRD_EVENT', 'headers' => [], 'body' => '']);

    expect($command->getBufferSize())->toBe(2);
    expect($command->getDroppedEventsCount())->toBe(1);

    /** @var SplQueue $queue */
    $queue = $propBuffer->getValue($command);
    $firstInQueue = $queue->dequeue();
    expect($firstInQueue['event_name'])->toBe('SECOND_EVENT');
    $secondInQueue = $queue->dequeue();
    expect($secondInQueue['event_name'])->toBe('THIRD_EVENT');
});

it('ensures heavyweight listeners implement ShouldQueue while telemetry listeners remain inline', function () {
    $heavyweightListeners = [
        LogFailedSipAuthListener::class,
        LogSipScannerListener::class,
        RegisterCompletedCallRecording::class,
        MarkBroadcastRecipientOutcome::class,
        RegisterCompletedVoicemailMessage::class,
    ];

    foreach ($heavyweightListeners as $listenerClass) {
        $reflection = new ReflectionClass($listenerClass);
        expect($reflection->implementsInterface(ShouldQueue::class))
            ->toBeTrue("Heavyweight listener {$listenerClass} must implement ShouldQueue to protect ESL event loop.");
    }

    $lightweightListeners = [
        BroadcastDashboardStatsOnFreeSwitchEvent::class,
        BroadcastTelephonyOnFreeSwitchEvent::class,
    ];

    foreach ($lightweightListeners as $listenerClass) {
        $reflection = new ReflectionClass($listenerClass);
        expect($reflection->implementsInterface(ShouldQueue::class))
            ->toBeFalse("Lightweight listener {$listenerClass} must execute inline without queue latency.");
    }
});

it('reports hasPendingData as false when FreeSwitchService is disconnected', function () {
    $fs = new FreeSwitchService(
        host: '127.0.0.1',
        port: 8021,
        password: 'test',
        timeout: 1
    );

    expect($fs->hasPendingData())->toBeFalse();
});
