<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FreeSwitchServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that starts or kills a Sofia gateway in FreeSWITCH.
 *
 * Dispatched when a gateway is enabled, disabled, or its profile
 * is changed. Uses 'sofia profile {profile} {startgw|killgw}
 * {gateway_name}' which allows granular trunk control without
 * rescanning the entire profile.
 *
 * The gateway name in FreeSWITCH is the gateway UUID (used as the
 * Sofia gateway name during XML generation).
 */
class ManageGateway implements ShouldQueue
{
    use Queueable;

    public const ACTION_START = 'start';

    public const ACTION_KILL = 'kill';

    /**
     * The Sofia profile name the gateway belongs to.
     */
    private string $profileName;

    /**
     * The Sofia gateway name (UUID used in gateway XML).
     */
    private string $gatewayName;

    /**
     * The action to perform: 'start' or 'kill'.
     */
    private string $action;

    /**
     * Human-readable description of what triggered the action.
     */
    private ?string $trigger;

    /**
     * Create a new job instance.
     *
     * @param  string  $profileName  The Sofia profile (e.g., 'external')
     * @param  string  $gatewayName  The gateway UUID / Sofia gateway name
     * @param  string  $action  'start' or 'kill'
     * @param  string|null  $trigger  Description of what changed
     */
    public function __construct(string $profileName, string $gatewayName, string $action, ?string $trigger = null)
    {
        $this->profileName = $profileName;
        $this->gatewayName = $gatewayName;
        $this->action = $action;
        $this->trigger = $trigger;
    }

    /**
     * Execute the gateway start/kill via the ESL service.
     *
     * Sends 'sofia profile {profile} {startgw|killgw} {name}'
     * to FreeSWITCH. On failure, dispatches a ReloadSofiaProfile
     * as a safety net so the profile XML is at least re-read.
     */
    public function handle(FreeSwitchServiceInterface $freeswitch): void
    {
        if (! in_array($this->action, [self::ACTION_START, self::ACTION_KILL], true)) {
            Log::warning('Sofia gateway action skipped because the action is unsupported.', [
                'profile' => $this->profileName,
                'gateway' => $this->gatewayName,
                'action' => $this->action,
                'trigger' => $this->trigger,
            ]);

            return;
        }

        try {
            $sofiaAction = $this->action === self::ACTION_START ? 'startgw' : 'killgw';
            $command = "sofia profile {$this->profileName} {$sofiaAction} {$this->gatewayName}";
            $result = $freeswitch->api($command);

            if ($result !== '') {
                Log::info('Sofia gateway action completed.', [
                    'profile' => $this->profileName,
                    'gateway' => $this->gatewayName,
                    'action' => $this->action,
                    'trigger' => $this->trigger,
                    'result' => $result,
                ]);
            } else {
                Log::warning('Sofia gateway action returned empty — dispatching profile rescan.', [
                    'profile' => $this->profileName,
                    'gateway' => $this->gatewayName,
                    'action' => $this->action,
                    'trigger' => $this->trigger,
                ]);

                // Fall back to a profile rescan so FreeSWITCH re-reads the gateway XML.
                ReloadSofiaProfile::dispatch($this->profileName, $this->trigger);
            }
        } catch (\Throwable $e) {
            Log::error('Sofia gateway action failed — changes persisted but gateway state may be stale.', [
                'profile' => $this->profileName,
                'gateway' => $this->gatewayName,
                'action' => $this->action,
                'trigger' => $this->trigger,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
