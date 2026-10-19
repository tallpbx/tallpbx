<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Listeners;

use App\Events\FreeSwitch\ChannelHangupComplete;
use App\Support\BroadcastSettlement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\CallBroadcast\Models\CallBroadcast;
use Modules\CallBroadcast\Models\CallBroadcastRecipient;

/**
 * Records a broadcast recipient's call outcome from its hangup event.
 *
 * The originate command chose the channel UUID (origination_uuid) and the
 * job stored it on the recipient; this listener matches the event's
 * Unique-ID back to that recipient and resolves answered/failed with the
 * hangup cause and billsec. Events for unrelated calls no-op.
 */
class MarkBroadcastRecipientOutcome implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The name of the queue the job should be sent to.
     */
    public ?string $queue = null;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        $this->queue = (string) config('freeswitch.esl.queue_name', 'default');
    }

    /**
     * Get the name of the listener's queue.
     */
    public function viaQueue(): string
    {
        return (string) config('freeswitch.esl.queue_name', 'default');
    }

    /**
     * Handle a channel hangup event for a broadcast call.
     */
    public function handle(ChannelHangupComplete $event): void
    {
        $uuid = $event->callUuid();

        if ($uuid === null) {
            return;
        }

        // Everything below is guarded: a transient DB failure must never
        // propagate out of the listener and kill the ESL event loop.
        try {
            $recipient = CallBroadcastRecipient::query()
                ->where('originate_uuid', $uuid)
                ->where('call_status', 'attempted')
                ->first();

            if ($recipient === null) {
                return;
            }

            // FreeSWITCH's native answered signal: the answer stamp is only
            // set when the call was actually answered.
            $answerStamp = $event->header('variable_answer_stamp');
            $billsec = (int) $event->header('variable_billsec', '0');
            $answered = $answerStamp !== null && $answerStamp !== '';

            $recipient->update([
                'call_status' => $answered ? 'answered' : 'failed',
                'call_duration' => $answered && $billsec > 0 ? $billsec : null,
                'hangup_cause' => $event->header('variable_hangup_cause'),
            ]);

            // Events arrive outside any request, so the tenant scope would
            // throw; the broadcast is resolved explicitly for settlement.
            $broadcast = CallBroadcast::withoutGlobalScope('tenant')->find($recipient->broadcast_id);

            if ($broadcast !== null) {
                BroadcastSettlement::completeIfSettled($broadcast);
            }
        } catch (\Throwable $exception) {
            // The listener must never take down the ESL loop for a bad event.
            Log::warning('Failed to record broadcast recipient outcome.', [
                'originate_uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
