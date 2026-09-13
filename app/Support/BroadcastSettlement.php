<?php

declare(strict_types=1);

namespace App\Support;

use Modules\CallBroadcast\Models\CallBroadcast;

/**
 * Owns the single rule that flips a broadcast from sending to completed.
 *
 * A broadcast is settled when none of its recipients is still pending or
 * attempted. Called by the outcome listener after each event and by the
 * reconcile sweep, so both paths converge on one completion decision.
 */
class BroadcastSettlement
{
    /**
     * Complete the broadcast when every recipient has resolved.
     */
    public static function completeIfSettled(CallBroadcast $broadcast): void
    {
        if ($broadcast->status !== 'sending') {
            return;
        }

        $unresolved = $broadcast->recipients()
            ->whereIn('call_status', ['pending', 'attempted'])
            ->exists();

        if (! $unresolved) {
            $broadcast->update(['status' => 'completed']);
        }
    }
}
