<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DialplanContext;
use App\Services\FreeSwitchServiceInterface;
use Modules\CallBroadcast\Jobs\SendCallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

function sendJobBroadcast(string $status = 'draft'): CallBroadcast
{
    $broadcast = CallBroadcast::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Alert',
        'status' => $status,
    ]);
    CallBroadcastRecipient::factory()->create(['broadcast_id' => $broadcast->id, 'phone_number' => '+15551234567', 'call_status' => 'pending']);
    CallBroadcastRecipient::factory()->create(['broadcast_id' => $broadcast->id, 'phone_number' => '+15559876543', 'call_status' => 'pending']);

    return $broadcast;
}

function sendJobMockFreeSwitch(): FreeSwitchServiceInterface
{
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->with(1)->andReturn(true);
    // A successful connect always releases the singleton ESL socket.
    $mock->shouldReceive('disconnect');
    app()->instance(FreeSwitchServiceInterface::class, $mock);

    return $mock;
}

it('originates a loopback call for every recipient and leaves the broadcast sending', function () {
    $broadcast = sendJobBroadcast();
    $mock = sendJobMockFreeSwitch();
    $context = app(DialplanContext::class)->internal((string) $broadcast->tenant_id);
    $mock->shouldReceive('bgapi')->once()->with(
        Mockery::on(fn (string $command): bool => str_contains($command, 'origination_uuid=')
            && str_contains($command, 'loopback/+15551234567/'.$context)
            && str_contains($command, '&playback(tone_stream://%(1000,0,640))')),
    )->andReturn('+OK');
    $mock->shouldReceive('bgapi')->once()->with(
        Mockery::on(fn (string $command): bool => str_contains($command, 'origination_uuid=')
            && str_contains($command, 'loopback/+15559876543/'.$context)
            && str_contains($command, '&playback(tone_stream://%(1000,0,640))')),
    )->andReturn('+OK');

    (new SendCallBroadcast($broadcast->id))->handle();

    $broadcast->refresh();
    // The job never force-completes: the broadcast status is untouched here
    // (the panel claims draft → sending; settlement happens via events).
    expect($broadcast->status)->toBe('draft');

    $recipients = $broadcast->recipients()->orderBy('phone_number')->get();
    expect($recipients->pluck('call_status')->all())->toBe(['attempted', 'attempted'])
        ->and($recipients->every(fn ($r) => $r->attempted_at !== null))->toBeTrue()
        // The channel UUID is chosen in advance so hangup events correlate.
        ->and($recipients->every(fn ($r) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $r->originate_uuid) === 1))->toBeTrue();
});

it('marks the broadcast failed when ESL is unreachable', function () {
    $broadcast = sendJobBroadcast();
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->with(1)->andReturn(false);
    app()->instance(FreeSwitchServiceInterface::class, $mock);

    (new SendCallBroadcast($broadcast->id))->handle();

    expect($broadcast->fresh()->status)->toBe('failed');
});

it('marks only the failing recipient failed and continues', function () {
    $broadcast = sendJobBroadcast();
    $mock = sendJobMockFreeSwitch();
    $context = app(DialplanContext::class)->internal((string) $broadcast->tenant_id);
    $mock->shouldReceive('bgapi')->once()->with(
        Mockery::on(fn (string $command): bool => str_contains($command, 'origination_uuid=')
            && str_contains($command, 'loopback/+15551234567/'.$context)),
    )->andThrow(new RuntimeException('ESL failed'));
    $mock->shouldReceive('bgapi')->once()->with(
        Mockery::on(fn (string $command): bool => str_contains($command, 'origination_uuid=')
            && str_contains($command, 'loopback/+15559876543/'.$context)),
    )->andReturn('+OK');

    (new SendCallBroadcast($broadcast->id))->handle();

    $broadcast->refresh();
    expect($broadcast->status)->toBe('draft')
        ->and($broadcast->recipients()->orderBy('phone_number')->pluck('call_status')->all())
        ->toBe(['failed', 'attempted']);
});

it('originates for a broadcast already claimed as sending', function () {
    $broadcast = sendJobBroadcast('sending');
    $mock = sendJobMockFreeSwitch();
    $context = app(DialplanContext::class)->internal((string) $broadcast->tenant_id);
    $mock->shouldReceive('bgapi')->twice()->andReturn('+OK');

    (new SendCallBroadcast($broadcast->id))->handle();

    expect($broadcast->fresh()->status)->toBe('sending');
});

it('marks a recipient failed when bgapi returns an empty response', function () {
    $broadcast = sendJobBroadcast();
    $mock = sendJobMockFreeSwitch();
    $context = app(DialplanContext::class)->internal((string) $broadcast->tenant_id);
    $mock->shouldReceive('bgapi')->once()->with(
        Mockery::on(fn (string $command): bool => str_contains($command, 'origination_uuid=')
            && str_contains($command, 'loopback/+15551234567/'.$context)),
    )->andReturn(''); // FreeSWITCH rejected the originate (e.g., -ERR with no Job-UUID)
    $mock->shouldReceive('bgapi')->once()->with(
        Mockery::on(fn (string $command): bool => str_contains($command, 'origination_uuid=')
            && str_contains($command, 'loopback/+15559876543/'.$context)),
    )->andReturn('+OK');

    (new SendCallBroadcast($broadcast->id))->handle();

    $broadcast->refresh();
    expect($broadcast->status)->toBe('draft')
        ->and($broadcast->recipients()->orderBy('phone_number')->pluck('call_status')->all())
        ->toBe(['failed', 'attempted'])
        // The rejection is recorded so the failure is diagnosable.
        ->and($broadcast->recipients()->orderBy('phone_number')->first()->hangup_cause)
        ->toBe('ORIGINATE_REJECTED');
});

it('aborts when the broadcast is already completed', function () {
    $broadcast = sendJobBroadcast('completed');
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldNotReceive('connect');
    app()->instance(FreeSwitchServiceInterface::class, $mock);

    (new SendCallBroadcast($broadcast->id))->handle();

    expect($broadcast->fresh()->status)->toBe('completed');
});

it('completes the broadcast when every recipient is rejected at originate time', function () {
    // In production the panel claims draft → sending before the job runs.
    $broadcast = sendJobBroadcast('sending');
    $mock = sendJobMockFreeSwitch();
    $mock->shouldReceive('bgapi')->twice()->andReturn(''); // both rejected

    (new SendCallBroadcast($broadcast->id))->handle();

    // No channels were created, so no hangup events will ever fire and the
    // sweep only touches attempted recipients — the job must settle this.
    expect($broadcast->fresh()->status)->toBe('completed');
});

it('stores the same originate uuid in the command and on the recipient', function () {
    $broadcast = sendJobBroadcast();
    $mock = sendJobMockFreeSwitch();
    $context = app(DialplanContext::class)->internal((string) $broadcast->tenant_id);
    $capturedUuid = null;
    $mock->shouldReceive('bgapi')->once()->with(
        Mockery::on(function (string $command) use (&$capturedUuid, $context): bool {
            // The design's core correlation claim: the uuid interpolated
            // into the command is the one matched to Unique-ID later.
            preg_match('/origination_uuid=([0-9a-f-]{36})/', $command, $matches);
            $capturedUuid = $matches[1] ?? null;

            return $capturedUuid !== null && str_contains($command, 'loopback/+15551234567/'.$context);
        }),
    )->andReturn('+OK');
    $mock->shouldReceive('bgapi')->once()->with(
        Mockery::on(fn (string $command): bool => str_contains($command, 'origination_uuid=')),
    )->andReturn('+OK');

    (new SendCallBroadcast($broadcast->id))->handle();

    $recipient = $broadcast->recipients()->orderBy('phone_number')->first();
    expect($capturedUuid)->not->toBeNull()
        ->and($recipient->fresh()->originate_uuid)->toBe($capturedUuid);
});

it('disconnects the esl session after the originate loop', function () {
    $broadcast = sendJobBroadcast();
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('connect')->once()->with(1)->andReturn(true);
    $mock->shouldReceive('bgapi')->twice()->andReturn('+OK 9d6c8b30-1111-2222-3333-444455556666');
    // The service is a container singleton: leaving the socket open keeps a
    // stale session across queue jobs in a long-lived worker.
    $mock->shouldReceive('disconnect')->once();
    app()->instance(FreeSwitchServiceInterface::class, $mock);

    (new SendCallBroadcast($broadcast->id))->handle();

    expect($broadcast->fresh()->status)->toBe('draft');
});
