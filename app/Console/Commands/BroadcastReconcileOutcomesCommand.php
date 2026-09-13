<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\BroadcastSettlement;
use Illuminate\Console\Command;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

/**
 * Resolve broadcast recipients whose outcome event never arrived.
 *
 * The ESL listener records outcomes from hangup events; when it was down
 * (or an event was lost) a recipient stays attempted forever. This sweep
 * marks recipients attempted before the configured window as failed with
 * cause NO_EVENT and settles their broadcast. Idempotent by design.
 */
class BroadcastReconcileOutcomesCommand extends Command
{
    protected $signature = 'broadcast:reconcile-outcomes';

    protected $description = 'Fail broadcast recipients whose outcome event never arrived';

    /**
     * Execute the reconcile sweep.
     */
    public function handle(): int
    {
        $window = max(1, (int) config('call-broadcast.outcome_window_minutes', 15));
        $cutoff = now()->subMinutes($window);

        $affected = CallBroadcastRecipient::query()
            ->where('call_status', 'attempted')
            ->whereNotNull('attempted_at')
            ->where('attempted_at', '<', $cutoff)
            ->get()
            ->groupBy('broadcast_id');

        foreach ($affected as $broadcastId => $recipients) {
            foreach ($recipients as $recipient) {
                // Guarded update: a hangup event that resolved the recipient
                // between the snapshot and this write must win.
                CallBroadcastRecipient::query()
                    ->whereKey($recipient->id)
                    ->where('call_status', 'attempted')
                    ->update([
                        'call_status' => 'failed',
                        'hangup_cause' => 'NO_EVENT',
                    ]);
            }

            // CLI runs without tenant context, so the global scope is bypassed.
            $broadcast = CallBroadcast::withoutGlobalScope('tenant')->find($broadcastId);

            if ($broadcast !== null) {
                BroadcastSettlement::completeIfSettled($broadcast);
            }
        }

        $this->info("Reconciled {$affected->flatten()->count()} stale recipient(s).");

        return self::SUCCESS;
    }
}
