<?php

declare(strict_types=1);

use App\Events\FreeSwitch\ChannelHangupComplete;
use App\Models\Tenant;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

function outcomeBroadcast(): CallBroadcast
{
    return CallBroadcast::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Alert',
        'status' => 'sending',
    ]);
}

function outcomeRecipient(CallBroadcast $broadcast, string $status = 'attempted'): CallBroadcastRecipient
{
    return CallBroadcastRecipient::factory()->create([
        'broadcast_id' => $broadcast->id,
        'phone_number' => '+15551234567',
        'call_status' => $status,
        'originate_uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
    ]);
}

it('marks an answered recipient and completes the broadcast when all settle', function (): void {
    $broadcast = outcomeBroadcast();
    outcomeRecipient($broadcast);

    event(new ChannelHangupComplete(
        eventName: 'CHANNEL_HANGUP_COMPLETE',
        headers: [
            'Unique-ID' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'variable_answer_stamp' => '2026-08-20 10:00:05',
            'variable_billsec' => '12',
            'variable_hangup_cause' => 'NORMAL_CLEARING',
        ],
        body: '',
    ));

    $recipient = $broadcast->recipients()->first();
    expect($recipient->fresh()->call_status)->toBe('answered')
        ->and($recipient->fresh()->call_duration)->toBe(12)
        ->and($recipient->fresh()->hangup_cause)->toBe('NORMAL_CLEARING')
        ->and($broadcast->fresh()->status)->toBe('completed');
});

it('marks an un-answered recipient failed and keeps the broadcast sending', function (): void {
    $broadcast = outcomeBroadcast();
    outcomeRecipient($broadcast);
    outcomeRecipient($broadcast, 'attempted');

    event(new ChannelHangupComplete(
        eventName: 'CHANNEL_HANGUP_COMPLETE',
        headers: [
            'Unique-ID' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'variable_hangup_cause' => 'NO_ANSWER',
        ],
        body: '',
    ));

    $recipient = $broadcast->recipients()->first();
    expect($recipient->fresh()->call_status)->toBe('failed')
        ->and($recipient->fresh()->hangup_cause)->toBe('NO_ANSWER')
        ->and($broadcast->fresh()->status)->toBe('sending');
});

it('ignores hangup events for unknown originate uuids', function (): void {
    $broadcast = outcomeBroadcast();
    outcomeRecipient($broadcast);

    event(new ChannelHangupComplete(
        eventName: 'CHANNEL_HANGUP_COMPLETE',
        headers: ['Unique-ID' => '6ba7b810-9dad-11d1-80b4-00c04fd430c9'],
        body: '',
    ));

    expect($broadcast->recipients()->first()->fresh()->call_status)->toBe('attempted');
});

it('ignores hangup events without a unique id', function (): void {
    $broadcast = outcomeBroadcast();
    outcomeRecipient($broadcast);

    event(new ChannelHangupComplete(eventName: 'CHANNEL_HANGUP_COMPLETE', headers: [], body: ''));

    expect($broadcast->recipients()->first()->fresh()->call_status)->toBe('attempted');
});
