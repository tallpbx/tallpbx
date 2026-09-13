<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FreeSwitchServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that tells FreeSWITCH to reload its XML configuration.
 *
 * Dispatched after runtime-affecting saves (gateways, dialplans,
 * SIP profiles, etc.). The 'reloadxml' command causes FreeSWITCH
 * to re-fetch all mod_xml_curl dynamic configuration.
 *
 * This is intentionally queued to avoid blocking the UI thread.
 * ESL unavailability does not cause the job to fail — the save
 * already succeeded and FreeSWITCH will pick up changes on its
 * next configuration reload.
 */
class ReloadFreeSwitchXml implements ShouldQueue
{
    use Queueable;

    /**
     * Description of what triggered the reload (for logging only).
     */
    private ?string $trigger;

    /**
     * Create a new job instance.
     *
     * @param  string|null  $trigger  Human-readable description of what changed
     */
    public function __construct(?string $trigger = null)
    {
        $this->trigger = $trigger;
    }

    /**
     * Execute the reloadxml command via the ESL service.
     *
     * ESL failures are caught and logged — they must not cause
     * the job to fail because the database changes are already
     * persisted.
     */
    public function handle(FreeSwitchServiceInterface $freeswitch): void
    {
        try {
            $result = $freeswitch->api('reloadxml');

            if ($result !== '') {
                Log::info('FreeSWITCH reloadxml completed.', [
                    'trigger' => $this->trigger,
                    'result' => $result,
                ]);
            } else {
                Log::warning('FreeSWITCH reloadxml returned empty response — ESL may be unavailable.', [
                    'trigger' => $this->trigger,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('FreeSWITCH reloadxml job failed — changes are persisted but XML may be stale.', [
                'trigger' => $this->trigger,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
