<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

it('adds the outcome columns to call broadcast recipients', function (): void {
    expect(Schema::hasColumns('call_broadcast_recipients', ['originate_uuid', 'hangup_cause']))->toBeTrue();
});

it('persists originate uuid and hangup cause through the factory', function (): void {
    $recipient = CallBroadcastRecipient::factory()->create([
        'originate_uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        'hangup_cause' => 'NO_ANSWER',
    ]);

    expect($recipient->fresh()->originate_uuid)->toBe('6ba7b810-9dad-11d1-80b4-00c04fd430c8')
        ->and($recipient->fresh()->hangup_cause)->toBe('NO_ANSWER');
});
