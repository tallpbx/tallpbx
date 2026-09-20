<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Modules\Security\Services\FirewallBanReconciler;

/**
 * Artisan command to reconcile Linux kernel dynamic ban sets with MariaDB.
 *
 * Checks kernel banned_ips / banned_ips6 sets against active SecurityBan records,
 * restoring missing bans with remaining TTL, removing stale elements, correcting
 * clock/TTL skew, and recovering missing filtering tables after unattended reboot.
 */
class SecurityReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:reconcile
        {--dry-run : Plan and report reconciliation actions without executing kernel modifications}
        {--recover-boot : Only recover an absent kernel filtering table}
        {--json : Output the reconciliation summary in structured JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile Linux nftables dynamic ban sets with active MariaDB bans and heal absent tables';

    /**
     * Execute the console command.
     */
    public function handle(FirewallBanReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $recoverBoot = (bool) $this->option('recover-boot');
        $json = (bool) $this->option('json');

        $result = $reconciler->reconcile(dryRun: $dryRun, recoverBootOnly: $recoverBoot);

        if ($json) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return empty($result['errors']) ? self::SUCCESS : self::FAILURE;
        }

        $this->info('=== TallPBX Firewall Ban-Set Reconciliation ===');

        if ($dryRun) {
            $this->warn('DRY RUN: Demonstrating planned reconciliation without executing host changes.');
        }

        if ($result['boot_recovered']) {
            $this->info('BOOT RECOVERY: Missing kernel table tallpbx_filter restored and applied successfully.');
        }

        $rows = [
            ['Boot Recovered', $result['boot_recovered'] ? 'YES' : 'NO'],
            ['Bans Restored', (string) count($result['added'])],
            ['Bans Removed', (string) count($result['removed'])],
            ['Bans Retimed', (string) count($result['retimed'])],
            ['Bans Skipped (<30s or cap)', (string) count($result['skipped'])],
            ['Errors', (string) count($result['errors'])],
        ];

        $this->table(['Action', 'Count'], $rows);

        if (! empty($result['added'])) {
            $this->line("\nRestored Kernel Bans:");
            foreach ($result['added'] as $ip => $ttl) {
                $this->line("  + {$ip} ({$ttl}s TTL)");
            }
        }

        if (! empty($result['removed'])) {
            $this->line("\nRemoved Extraneous Bans:");
            foreach ($result['removed'] as $ip) {
                $this->line("  - {$ip}");
            }
        }

        if (! empty($result['retimed'])) {
            $this->line("\nRetimed Skewed Bans:");
            foreach ($result['retimed'] as $ip => $ttl) {
                $this->line("  ~ {$ip} (re-timed to {$ttl}s)");
            }
        }

        if (! empty($result['errors'])) {
            $this->error("\nErrors Encountered:");
            foreach ($result['errors'] as $error) {
                $this->error("  ! {$error}");
            }

            return self::FAILURE;
        }

        $this->info("\nReconciliation completed successfully.");

        return self::SUCCESS;
    }
}
