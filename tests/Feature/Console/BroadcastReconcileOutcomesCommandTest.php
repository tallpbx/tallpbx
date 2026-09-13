<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

it('marks stale attempted recipients failed with no event cause', function (): void {
    config(['call-broadcast.outcome_window_minutes' => 15]);
    $broadcast = CallBroadcast::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Alert',
        'status' => 'sending',
    ]);
    $stale = CallBroadcastRecipient::factory()->create([
        'broadcast_id' => $broadcast->id,
        'call_status' => 'attempted',
        'originate_uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        'attempted_at' => now()->subMinutes(20),
    ]);
    $fresh = CallBroadcastRecipient::factory()->create([
        'broadcast_id' => $broadcast->id,
        'call_status' => 'attempted',
        'originate_uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c9',
        'attempted_at' => now()->subMinutes(5),
    ]);

    Artisan::call('broadcast:reconcile-outcomes');

    expect($stale->fresh()->call_status)->toBe('failed')
        ->and($stale->fresh()->hangup_cause)->toBe('NO_EVENT')
        ->and($fresh->fresh()->call_status)->toBe('attempted');
});

it('completes the broadcast once every recipient has settled', function (): void {
    $broadcast = CallBroadcast::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Alert',
        'status' => 'sending',
    ]);
    CallBroadcastRecipient::factory()->create([
        'broadcast_id' => $broadcast->id,
        'call_status' => 'attempted',
        'originate_uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        'attempted_at' => now()->subMinutes(20),
    ]);

    Artisan::call('broadcast:reconcile-outcomes');

    expect($broadcast->fresh()->status)->toBe('completed');
});

it('is idempotent across repeated runs', function (): void {
    config(['call-broadcast.outcome_window_minutes' => 15]);
    $broadcast = CallBroadcast::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Alert',
        'status' => 'sending',
    ]);
    $recipient = CallBroadcastRecipient::factory()->create([
        'broadcast_id' => $broadcast->id,
        'call_status' => 'attempted',
        'originate_uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        'attempted_at' => now()->subMinutes(20),
    ]);

    Artisan::call('broadcast:reconcile-outcomes');
    Artisan::call('broadcast:reconcile-outcomes');

    expect($recipient->fresh()->call_status)->toBe('failed')
        ->and($recipient->fresh()->hangup_cause)->toBe('NO_EVENT')
        ->and($broadcast->fresh()->status)->toBe('completed');
});

it('does not overwrite a recipient that already resolved', function (): void {
    config(['call-broadcast.outcome_window_minutes' => 15]);
    $broadcast = CallBroadcast::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'Alert',
        'status' => 'sending',
    ]);
    // A recipient the listener already resolved (e.g. the hangup event
    // arrived before the sweep tick) must keep its outcome. The guarded
    // update also protects the sub-millisecond interleaving that is not
    // deterministically testable — this asserts the end-state contract.
    $resolved = CallBroadcastRecipient::factory()->create([
        'broadcast_id' => $broadcast->id,
        'call_status' => 'failed',
        'hangup_cause' => 'NO_ANSWER',
        'originate_uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        'attempted_at' => now()->subMinutes(20),
    ]);

    Artisan::call('broadcast:reconcile-outcomes');

    expect($resolved->fresh()->hangup_cause)->toBe('NO_ANSWER');
});
