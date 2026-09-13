<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FreeSwitchServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that rescans a specific Sofia profile in FreeSWITCH.
 *
 * Dispatched after SIP profile or gateway changes that affect a
 * specific Sofia profile. Uses 'sofia profile {name} rescan' which
 * is faster and more targeted than a full reloadxml — FreeSWITCH
 * re-reads only the configuration for that profile.
 *
 * Falls back to a full reloadxml if the profile-specific rescan
 * fails, ensuring changes are eventually picked up.
 */
class ReloadSofiaProfile implements ShouldQueue
{
    use Queueable;

    /**
     * The Sofia profile name to rescan (e.g., 'internal', 'external').
     */
    private string $profileName;

    /**
     * Human-readable description of what triggered the reload (for logging).
     */
    private ?string $trigger;

    /**
     * Create a new job instance.
     *
     * @param  string  $profileName  The Sofia profile name
     * @param  string|null  $trigger  Description of what changed
     */
    public function __construct(string $profileName, ?string $trigger = null)
    {
        $this->profileName = $profileName;
        $this->trigger = $trigger;
    }

    /**
     * Execute the profile rescan via the ESL service.
     *
     * Sends 'sofia profile {name} rescan' to FreeSWITCH. On failure,
     * falls back to a full reloadxml to ensure configuration changes
     * are not lost.
     */
    public function handle(FreeSwitchServiceInterface $freeswitch): void
    {
        try {
            $command = "sofia profile {$this->profileName} rescan";
            $result = $freeswitch->api($command);

            if ($result !== '') {
                Log::info('Sofia profile rescan completed.', [
                    'profile' => $this->profileName,
                    'trigger' => $this->trigger,
                    'result' => $result,
                ]);
            } else {
                Log::warning('Sofia profile rescan returned empty — falling back to reloadxml.', [
                    'profile' => $this->profileName,
                    'trigger' => $this->trigger,
                ]);

                // Fall back to full reloadxml
                $fallbackResult = $freeswitch->api('reloadxml');

                Log::info('Fallback reloadxml completed.', [
                    'profile' => $this->profileName,
                    'result' => $fallbackResult,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Sofia profile rescan failed — changes persisted but XML may be stale.', [
                'profile' => $this->profileName,
                'trigger' => $this->trigger,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
