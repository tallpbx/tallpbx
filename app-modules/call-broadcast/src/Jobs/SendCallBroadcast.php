<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Jobs;

use App\Services\DialplanContext;
use App\Services\FreeSwitchServiceInterface;
use App\Support\BroadcastSettlement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\CallBroadcast\Models\CallBroadcast;

/**
 * Queued job that originates a broadcast call to every recipient.
 *
 * Each recipient is called through loopback into the tenant's internal
 * context, so outbound routes and number translations apply; the
 * &playback B-leg answers and plays the configured media after pickup.
 */
class SendCallBroadcast implements ShouldQueue
{
    use Queueable;

    /**
     * The broadcast to send.
     */
    public string $broadcastId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $broadcastId)
    {
        $this->broadcastId = $broadcastId;
    }

    /**
     * Originate the broadcast calls and update the statuses.
     */
    public function handle(): void
    {
        $broadcast = CallBroadcast::withoutGlobalScope('tenant')->find($this->broadcastId);

        // The panel has already claimed the broadcast (draft → sending) before
        // this job runs, so both states are valid; completed/failed broadcasts
        // abort stale jobs.
        if ($broadcast === null || ! in_array($broadcast->status, ['draft', 'sending'], true)) {
            return;
        }

        // Resolve the container singleton (the interface binding); the
        // concrete class cannot be autowired.
        $freeSwitch = app(FreeSwitchServiceInterface::class);

        if (! $freeSwitch->connect(timeout: 1)) {
            $broadcast->update(['status' => 'failed']);
            Log::warning('Call broadcast failed: FreeSWITCH ESL unreachable.', [
                'broadcast_id' => $broadcast->id,
            ]);

            return;
        }

        $context = app(DialplanContext::class)->internal((string) $broadcast->tenant_id);
        $callerId = (string) config('call-broadcast.caller_id_number', '');
        $media = (string) config('call-broadcast.media', 'tone_stream://%(1000,0,640)');
        $pacing = max(0, (int) config('call-broadcast.pacing_seconds', 0));

        $recipients = $broadcast->recipients()
            ->where('call_status', 'pending')
            ->orderBy('phone_number')
            ->get();

        // The ESL service is a container singleton: release the socket when
        // the send finishes so a long-lived worker never keeps a stale
        // session across jobs (matches the voicemail/ACL convention).
        try {
            foreach ($recipients as $index => $recipient) {
                if ($index > 0 && $pacing > 0) {
                    usleep($pacing * 1_000_000);
                }

                // Defense in depth: the create form validates numbers, but the
                // dial string is interpolated here — never trust the DB blindly.
                if (preg_match('/^\+?[0-9]{6,15}$/', $recipient->phone_number) !== 1) {
                    $recipient->update([
                        'call_status' => 'failed',
                        'hangup_cause' => 'INVALID_NUMBER',
                        'attempted_at' => now(),
                    ]);

                    continue;
                }

                try {
                    // The channel UUID is chosen here so the hangup-complete
                    // event can be correlated back to this recipient.
                    $originateUuid = Str::uuid()->toString();

                    // Loopback re-enters the tenant dialplan with the number as
                    // the destination; &playback answers and plays the media.
                    $command = sprintf(
                        'originate {origination_uuid=%s,origination_caller_id_number=%s}loopback/%s/%s &playback(%s)',
                        $originateUuid,
                        $callerId,
                        $recipient->phone_number,
                        $context,
                        $media,
                    );
                    $result = $freeSwitch->bgapi($command);

                    // The ESL service degrades silently: an empty response means
                    // FreeSWITCH did not accept the originate (e.g., -ERR with no
                    // Job-UUID), which must not count as an attempt.
                    if ($result === '') {
                        throw new \RuntimeException('FreeSWITCH did not accept the originate command.');
                    }

                    $recipient->update([
                        'call_status' => 'attempted',
                        'attempted_at' => now(),
                        'originate_uuid' => $originateUuid,
                    ]);
                } catch (\RuntimeException $exception) {
                    Log::warning('Call broadcast recipient failed.', [
                        'broadcast_id' => $broadcast->id,
                        'recipient_id' => $recipient->id,
                        'error' => $exception->getMessage(),
                    ]);

                    $recipient->update([
                        'call_status' => 'failed',
                        'hangup_cause' => 'ORIGINATE_REJECTED',
                        'attempted_at' => now(),
                    ]);
                }
            }
        } finally {
            $freeSwitch->disconnect();
        }

        // Outcomes arrive via hangup events after the loop; the broadcast
        // stays sending until every recipient settles. Recipients rejected
        // at originate time (INVALID_NUMBER/ORIGINATE_REJECTED) never create
        // channels, so no hangup events will ever fire for them — settle
        // here when nothing is left in flight. The status is refreshed in
        // case a concurrent claim flipped the row while this job ran.
        $broadcast->refresh();
        BroadcastSettlement::completeIfSettled($broadcast);
    }
}
