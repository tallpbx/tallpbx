<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecuritySetting;

/**
 * Artisan command to display the active host firewall engine status,
 * active bans, and kernel packet filtering state.
 */
class SecurityStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display active host firewall status, intrusion bans, and nftables state';

    /**
     * Execute the console command.
     */
    public function handle(
        SecurityExecutorInterface $executor,
        SecurityBanServiceInterface $banService,
    ): int {
        $this->info('=== TallPBX Security Engine Status ===');

        $firewallEnabled = SecuritySetting::getBoolean('firewall_enabled', true);
        $defaultPolicy = SecuritySetting::get('firewall_default_policy', 'drop');
        $intrusionEnabled = SecuritySetting::getBoolean('intrusion_detection_enabled', true);

        $this->table(
            ['Setting', 'Current Value'],
            [
                ['Firewall Status', $firewallEnabled ? 'ACTIVE' : 'DISABLED'],
                ['Default Policy', strtoupper((string) $defaultPolicy)],
                ['Intrusion Detection', $intrusionEnabled ? 'ACTIVE' : 'DISABLED'],
                ['Whitelist Entries', (string) SecurityIpList::whitelist()->count()],
                ['Blacklist Entries', (string) SecurityIpList::blacklist()->count()],
                ['Active Custom Rules', (string) SecurityRule::active()->count()],
            ]
        );

        // Active Bans
        $activeBans = $banService->getActiveBans();
        $this->newLine();
        $this->info("=== Active Host Bans ({$activeBans->count()}) ===");

        if ($activeBans->isEmpty()) {
            $this->line('No active bans.');
        } else {
            $banRows = $activeBans->map(function ($ban): array {
                $timeRemaining = $ban->timeRemaining();
                $expiresText = $timeRemaining === null
                    ? 'Permanent'
                    : ($timeRemaining > 0 ? "{$timeRemaining}s remaining" : 'Expired');

                return [
                    $ban->ip_address,
                    $ban->vector,
                    $ban->reason,
                    (string) $ban->attempt_count,
                    $ban->banned_at->toDateTimeString(),
                    $expiresText,
                ];
            })->all();

            $this->table(
                ['IP Address', 'Vector', 'Reason', 'Attempts', 'Banned At', 'Expires'],
                $banRows
            );
        }

        // Kernel status
        $this->newLine();
        $this->info('=== Kernel nftables Ruleset Status ===');
        $kernelStatus = trim($executor->status());
        if ($kernelStatus !== '') {
            $this->line($kernelStatus);
        } else {
            $this->warn('Kernel ruleset status is currently empty or helper script not installed.');
        }

        return self::SUCCESS;
    }
}
