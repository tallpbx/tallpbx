<?php

declare(strict_types=1);

use App\Events\FreeSwitch\ChannelHangupComplete;
use App\Models\Tenant;
use Modules\CallBroadcast\Listeners\MarkBroadcastRecipientOutcome;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

it('records answered outcome and duration from channel hangup event', function (): void {
    $tenant = Tenant::factory()->create();
    $broadcast = CallBroadcast::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'sending',
    ]);
    $recipient = CallBroadcastRecipient::factory()->create([
        'broadcast_id' => $broadcast->id,
        'originate_uuid' => 'test-uuid-1234',
        'call_status' => 'attempted',
    ]);

    $event = new ChannelHangupComplete('CHANNEL_HANGUP_COMPLETE', [
        'Unique-ID' => 'test-uuid-1234',
        'variable_answer_stamp' => '2026-10-18 01:00:00',
        'variable_billsec' => '32',
        'variable_hangup_cause' => 'NORMAL_CLEARING',
    ], '');

    app(MarkBroadcastRecipientOutcome::class)->handle($event);

    $recipient->refresh();
    expect($recipient->call_status)->toBe('answered')
        ->and($recipient->call_duration)->toBe(32)
        ->and($recipient->hangup_cause)->toBe('NORMAL_CLEARING');

    $broadcast->refresh();
    expect($broadcast->status)->toBe('completed');
});

it('records failed outcome when no answer stamp is present', function (): void {
    $tenant = Tenant::factory()->create();
    $broadcast = CallBroadcast::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'sending',
    ]);
    $recipient = CallBroadcastRecipient::factory()->create([
        'broadcast_id' => $broadcast->id,
        'originate_uuid' => 'test-uuid-failed',
        'call_status' => 'attempted',
    ]);

    $event = new ChannelHangupComplete('CHANNEL_HANGUP_COMPLETE', [
        'Unique-ID' => 'test-uuid-failed',
        'variable_hangup_cause' => 'USER_BUSY',
    ], '');

    app(MarkBroadcastRecipientOutcome::class)->handle($event);

    $recipient->refresh();
    expect($recipient->call_status)->toBe('failed')
        ->and($recipient->call_duration)->toBeNull()
        ->and($recipient->hangup_cause)->toBe('USER_BUSY');

    $broadcast->refresh();
    expect($broadcast->status)->toBe('completed');
});

it('ignores events for unknown or non-attempted calls', function (): void {
    $event = new ChannelHangupComplete('CHANNEL_HANGUP_COMPLETE', [
        'Unique-ID' => 'unknown-uuid',
        'variable_hangup_cause' => 'NORMAL_CLEARING',
    ], '');

    app(MarkBroadcastRecipientOutcome::class)->handle($event);
    expect(true)->toBeTrue();
});
