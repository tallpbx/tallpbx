<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\FreeSwitch\BackgroundJob;
use App\Events\FreeSwitch\CallUpdate;
use App\Events\FreeSwitch\ChannelAnswer;
use App\Events\FreeSwitch\ChannelBridge;
use App\Events\FreeSwitch\ChannelCreate;
use App\Events\FreeSwitch\ChannelDestroy;
use App\Events\FreeSwitch\ChannelExecute;
use App\Events\FreeSwitch\ChannelExecuteComplete;
use App\Events\FreeSwitch\ChannelHangup;
use App\Events\FreeSwitch\ChannelHangupComplete;
use App\Events\FreeSwitch\ChannelOutgoing;
use App\Events\FreeSwitch\ChannelProgress;
use App\Events\FreeSwitch\ChannelProgressMedia;
use App\Events\FreeSwitch\ChannelState;
use App\Events\FreeSwitch\ChannelUnbridge;
use App\Events\FreeSwitch\Codec;
use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\Dtmf;
use App\Events\FreeSwitch\Heartbeat;
use App\Events\FreeSwitch\PresenceIn;
use App\Events\FreeSwitch\RecordStart;
use App\Events\FreeSwitch\RecordStop;
use App\Events\FreeSwitch\SessionHeartbeat;
use App\Events\FreeSwitch\SipScannerDetected;
use App\Events\FreeSwitch\SofiaExpire;
use App\Events\FreeSwitch\SofiaFailedAuth;
use App\Events\FreeSwitch\SofiaRegister;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-running command that listens for FreeSWITCH ESL events and
 * dispatches them as Laravel events for the application to react to.
 *
 * This command maintains a persistent ESL connection, processes
 * incoming events in a loop, and handles reconnection when the
 * FreeSWITCH ESL socket drops. It is designed to run as a
 * systemd service for production deployments.
 *
 * Usage:
 *   php artisan freeswitch:listen
 *
 * Event Mapping:
 *   FreeSWITCH ESL events are mapped to typed Laravel event classes
 *   so that listeners can type-hint against specific event types.
 */
class FreeSwitchListenCommand extends Command
{
    /**
     * The console command signature.
     */
    protected $signature = 'freeswitch:listen
        {--once : Process a single event and exit (useful for testing)}
        {--timeout=0 : Maximum seconds to run before exiting (0 = forever)}
        {--buffer-size= : Maximum in-memory event buffer capacity before overflow drop}';

    /**
     * The console command description.
     */
    protected $description = 'Listen for FreeSWITCH ESL events and dispatch them as Laravel events';

    /**
     * In-memory event buffer holding raw ESL events before dispatch.
     *
     * @var \SplQueue<array{event_name: string, headers: array<string, string>, body: string}>
     */
    private \SplQueue $buffer;

    /**
     * Maximum capacity of the in-memory event buffer.
     */
    private int $maxBufferSize = 1000;

    /**
     * Total count of dropped events due to buffer overflow.
     */
    private int $droppedEventsCount = 0;

    /**
     * Event name to Laravel event class mapping.
     *
     * Maps FreeSWITCH event type strings to their corresponding
     * Laravel event classes for dispatch.
     *
     * @var array<string, class-string>
     */
    private const EVENT_MAP = [
        'CHANNEL_CREATE' => ChannelCreate::class,
        'CHANNEL_ANSWER' => ChannelAnswer::class,
        'CHANNEL_HANGUP' => ChannelHangup::class,
        'CHANNEL_HANGUP_COMPLETE' => ChannelHangupComplete::class,
        'CHANNEL_DESTROY' => ChannelDestroy::class,
        'CHANNEL_BRIDGE' => ChannelBridge::class,
        'CHANNEL_UNBRIDGE' => ChannelUnbridge::class,
        'CHANNEL_OUTGOING' => ChannelOutgoing::class,
        'CHANNEL_PROGRESS' => ChannelProgress::class,
        'CHANNEL_PROGRESS_MEDIA' => ChannelProgressMedia::class,
        'CHANNEL_EXECUTE' => ChannelExecute::class,
        'CHANNEL_EXECUTE_COMPLETE' => ChannelExecuteComplete::class,
        'CHANNEL_STATE' => ChannelState::class,
        'CALL_UPDATE' => CallUpdate::class,
        'CODEC' => Codec::class,
        'BACKGROUND_JOB' => BackgroundJob::class,
        'SESSION_HEARTBEAT' => SessionHeartbeat::class,
        'PRESENCE_IN' => PresenceIn::class,
        'DTMF' => Dtmf::class,
        'RECORD_START' => RecordStart::class,
        'RECORD_STOP' => RecordStop::class,
        'CUSTOM' => CustomEvent::class,
        'HEARTBEAT' => Heartbeat::class,
        'SOFIA_REGISTER' => SofiaRegister::class,
        'SOFIA_EXPIRE' => SofiaExpire::class,
    ];

    /**
     * Execute the console command.
     *
     * Connects to the FreeSWITCH ESL socket, then enters an
     * event loop that reads and dispatches events until the
     * timeout is reached or the process receives a signal.
     */
    public function handle(FreeSwitchServiceInterface $freeswitch): int
    {
        $this->components->info('FreeSWITCH Event Listener starting...');

        $startTime = time();
        $timeout = (int) $this->option('timeout');
        $once = (bool) $this->option('once');
        $reconnectInterval = (int) config('freeswitch.esl.reconnect_interval', 5);

        $bufferOption = $this->option('buffer-size');
        $this->maxBufferSize = $bufferOption !== null && $bufferOption !== ''
            ? (int) $bufferOption
            : (int) config('freeswitch.esl.buffer_size', 1000);

        $this->buffer = new \SplQueue;
        $this->droppedEventsCount = 0;

        // ── Establish initial connection ──────────────────────────
        if (! $freeswitch->connect()) {
            $this->components->error('Failed to connect to FreeSWITCH ESL.');

            return self::FAILURE;
        }

        $freeswitch->subscribeToEvents();

        $this->components->info('Connected to FreeSWITCH ESL. Listening for events...');

        // ── Event loop ────────────────────────────────────────────
        while (true) {
            // Check timeout
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                $this->components->info('Timeout reached. Shutting down.');

                break;
            }

            // Reconnect if the connection was dropped
            if (! $freeswitch->isConnected()) {
                $this->components->warn('ESL connection lost. Reconnecting...');
                Log::warning('FreeSWITCH ESL connection lost, attempting reconnect.', [
                    'host' => config('freeswitch.esl.host'),
                    'port' => config('freeswitch.esl.port'),
                ]);

                sleep($reconnectInterval);

                if (! $freeswitch->connect()) {
                    $this->components->warn('Reconnect failed. Retrying...');

                    continue;
                }

                $freeswitch->subscribeToEvents();

                $this->components->info('Reconnected to FreeSWITCH ESL.');
            }

            // 1. If buffer is empty, read next event from socket (blocking up to timeout)
            if ($this->buffer->isEmpty()) {
                $event = $freeswitch->recvEvent();

                if ($event === null) {
                    // Socket timed out or was closed; loop back to check connection
                    usleep(100000); // 100ms pause to prevent tight loop

                    continue;
                }

                $this->enqueueEvent($event);
            }

            // 2. Drain any additional pending events immediately from socket into buffer
            $hasPending = false;
            if (method_exists($freeswitch, 'hasPendingData')) {
                try {
                    $hasPending = $freeswitch->hasPendingData();
                } catch (\Throwable) {
                    $hasPending = false;
                }
            }

            while ($hasPending && $this->buffer->count() < $this->maxBufferSize) {
                $pendingEvent = $freeswitch->recvEvent();
                if ($pendingEvent === null) {
                    break;
                }
                $this->enqueueEvent($pendingEvent);

                try {
                    $hasPending = $freeswitch->hasPendingData();
                } catch (\Throwable) {
                    $hasPending = false;
                }
            }

            // 3. Dispatch next event from buffer
            if (! $this->buffer->isEmpty()) {
                $eventToDispatch = $this->buffer->dequeue();
                $this->dispatchEvent($eventToDispatch);
            }

            if ($once) {
                break;
            }
        }

        $freeswitch->disconnect();
        $this->components->info('FreeSWITCH Event Listener stopped.');

        return self::SUCCESS;
    }

    /**
     * Enqueue a raw ESL event into the bounded buffer, dropping the oldest
     * event if capacity is reached to prevent unbounded memory growth.
     *
     * @param  array{event_name: string, headers: array<string, string>, body: string}  $event
     */
    public function enqueueEvent(array $event): void
    {
        if ($this->buffer->count() >= $this->maxBufferSize) {
            $dropped = $this->buffer->dequeue();
            $this->droppedEventsCount++;

            Log::warning('FreeSWITCH ESL event buffer overflow. Dropped oldest unconsumed event.', [
                'dropped_event' => $dropped['event_name'] ?? 'UNKNOWN',
                'buffer_size' => $this->maxBufferSize,
                'total_dropped' => $this->droppedEventsCount,
            ]);
        }

        $this->buffer->enqueue($event);
    }

    /**
     * Get the current count of events in the buffer.
     */
    public function getBufferSize(): int
    {
        return $this->buffer->count();
    }

    /**
     * Get the count of dropped events due to buffer overflow.
     */
    public function getDroppedEventsCount(): int
    {
        return $this->droppedEventsCount;
    }

    /**
     * Map a raw ESL event to its typed Laravel event and dispatch it.
     *
     * Known event types are dispatched as their specific event class.
     * Unknown event types are dispatched as a generic CustomEvent
     * so that listeners can still react to them if needed.
     *
     * @param  array{event_name: string, headers: array<string, string>, body: string}  $rawEvent
     */
    private function dispatchEvent(array $rawEvent): void
    {
        $eventName = $rawEvent['event_name'];
        $eventClass = self::EVENT_MAP[$eventName] ?? CustomEvent::class;

        if ($eventName === 'CUSTOM' && ($rawEvent['headers']['Event-Subclass'] ?? null) === 'sofia::failed_auth') {
            $eventClass = SofiaFailedAuth::class;
        }

        if ($eventName === 'CUSTOM' && ($rawEvent['headers']['Event-Subclass'] ?? null) === 'tallpbx::sip_scanner_detected') {
            $eventClass = SipScannerDetected::class;
        }

        $laravelEvent = new $eventClass(
            eventName: $eventName,
            headers: $rawEvent['headers'],
            body: $rawEvent['body'],
        );

        event($laravelEvent);

        // Heartbeats arrive every ~20 s purely as keepalives; logging each dispatch
        // floods production logs (~4,300 lines/day) with no diagnostic value. The
        // event is still dispatched and broadcast — the heartbeat drives the
        // wire:poll-free telephony views' periodic refresh.
        if ($eventName !== 'HEARTBEAT' && $eventName !== 'SESSION_HEARTBEAT') {
            // Log at debug level to avoid flooding production logs
            Log::debug('FreeSWITCH event dispatched.', [
                'event' => $eventName,
                'call_uuid' => $laravelEvent->callUuid(),
            ]);
        }
    }
}
