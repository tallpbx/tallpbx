<?php

declare(strict_types=1);

namespace Tests\Feature\FreeSwitch;

use App\Events\FreeSwitch\ChannelCreate;
use App\Events\FreeSwitch\Heartbeat;
use App\Listeners\BroadcastDashboardStatsOnFreeSwitchEvent;
use App\Listeners\BroadcastTelephonyOnFreeSwitchEvent;
use App\Services\DialplanContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\CallBroadcast\Listeners\MarkBroadcastRecipientOutcome;
use Modules\CallRecordings\Listeners\RegisterCompletedCallRecording;
use Modules\CallRecordings\Services\CallRecordingService;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;
use Modules\Security\Listeners\LogFailedSipAuthListener;
use Modules\Security\Listeners\LogSipScannerListener;
use Modules\VoicemailMessages\Listeners\RegisterCompletedVoicemailMessage;
use Modules\VoicemailMessages\Services\VoicemailMessageService;
use RuntimeException;

test('esl queued listeners bind to configured freeswitch queue name', function (): void {
    config(['freeswitch.esl.queue_name' => 'custom-esl-queue']);

    $incidentService = $this->createMock(SecurityIncidentServiceInterface::class);
    $banService = $this->createMock(SecurityBanServiceInterface::class);
    $recordingService = $this->createMock(CallRecordingService::class);
    $dialplanContext = $this->createMock(DialplanContext::class);
    $voicemailService = $this->createMock(VoicemailMessageService::class);

    $listeners = [
        new LogFailedSipAuthListener($incidentService),
        new LogSipScannerListener($banService),
        new RegisterCompletedCallRecording($recordingService, $dialplanContext),
        new RegisterCompletedVoicemailMessage($voicemailService, $dialplanContext),
        new MarkBroadcastRecipientOutcome,
    ];

    foreach ($listeners as $listener) {
        expect($listener->viaQueue())->toBe('custom-esl-queue')
            ->and($listener->queue)->toBe('custom-esl-queue');
    }
});

test('esl queued listeners default to default queue when no config is provided', function (): void {
    config(['freeswitch.esl.queue_name' => 'default']);

    $incidentService = $this->createMock(SecurityIncidentServiceInterface::class);
    $banService = $this->createMock(SecurityBanServiceInterface::class);
    $recordingService = $this->createMock(CallRecordingService::class);
    $dialplanContext = $this->createMock(DialplanContext::class);
    $voicemailService = $this->createMock(VoicemailMessageService::class);

    $listeners = [
        new LogFailedSipAuthListener($incidentService),
        new LogSipScannerListener($banService),
        new RegisterCompletedCallRecording($recordingService, $dialplanContext),
        new RegisterCompletedVoicemailMessage($voicemailService, $dialplanContext),
        new MarkBroadcastRecipientOutcome,
    ];

    foreach ($listeners as $listener) {
        expect($listener->viaQueue())->toBe('default')
            ->and($listener->queue)->toBe('default');
    }
});

test('broadcast telephony listener logs warning when dispatch encounters exception', function (): void {
    config(['broadcasting.test_broadcasts' => true]);

    Log::shouldReceive('warning')
        ->once()
        ->with('FreeSWITCH telephony broadcast failed', \Mockery::on(function ($context) {
            return ($context['event'] ?? null) === 'CHANNEL_CREATE'
                && ($context['error'] ?? null) === 'Broadcast connection failed';
        }));

    Event::shouldReceive('dispatch')
        ->andThrow(new RuntimeException('Broadcast connection failed'));

    $listener = new BroadcastTelephonyOnFreeSwitchEvent;
    $event = new ChannelCreate('CHANNEL_CREATE', [
        'Unique-ID' => 'test-uuid-123',
    ], '');

    $listener->handle($event);
});

test('broadcast dashboard stats listener logs warning when dispatch encounters exception', function (): void {
    config(['broadcasting.test_broadcasts' => true]);

    Log::shouldReceive('warning')
        ->once()
        ->with('FreeSWITCH dashboard stats broadcast failed', \Mockery::on(function ($context) {
            return ($context['event'] ?? null) === 'HEARTBEAT'
                && ($context['error'] ?? null) === 'Reverb socket refused';
        }));

    Event::shouldReceive('dispatch')
        ->andThrow(new RuntimeException('Reverb socket refused'));

    $listener = new BroadcastDashboardStatsOnFreeSwitchEvent;
    $event = new Heartbeat('HEARTBEAT', [], '');

    $listener->handle($event);
});
