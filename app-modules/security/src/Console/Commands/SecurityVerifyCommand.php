<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Security\Services\FirewallSyncVerifier;
use Modules\Security\Support\FirewallSyncStatus;

/**
 * Artisan command to verify host firewall synchronization against the running Linux kernel.
 *
 * Compares desired database ruleset state against the authoritative root helper sidecar
 * provenance record and live nftables packet filtering chains.
 */
class SecurityVerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:verify
                            {--strict : Return failure exit code (2) if sync status is unknown or unverified}
                            {--json : Output status as machine-readable JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify Linux nftables firewall ruleset synchronization and provenance';

    /**
     * Execute the console command.
     */
    public function handle(FirewallSyncVerifier $verifier): int
    {
        $status = $verifier->verify();

        if ($this->option('json')) {
            $this->line((string) json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($status->isDrift()) {
                Log::warning('Firewall sync drift detected', $status->toArray());

                return self::FAILURE;
            }

            if ($status->isUnknown() && $this->option('strict')) {
                return 2;
            }

            return self::SUCCESS;
        }

        $this->info('=== TallPBX Firewall Sync Verification ===');

        $this->table(
            ['Metric', 'Value'],
            [
                ['State', strtoupper($status->state)],
                ['Applied Policy', $status->appliedPolicy ? strtoupper($status->appliedPolicy) : 'N/A'],
                ['Applied At', $status->appliedAt ?? 'N/A'],
                ['Desired Digest', $status->desiredDigest ?? 'N/A'],
                ['Applied Digest', $status->appliedDigest ?? 'N/A'],
            ]
        );

        if ($status->issues !== []) {
            $this->newLine();
            $this->warn('Issues Detected:');
            foreach ($status->issues as $issue) {
                $this->line(" - {$issue}");
            }
        }

        return $this->resolveExitCode($status);
    }

    /**
     * Resolve the console command exit code based on verification status and options.
     */
    private function resolveExitCode(FirewallSyncStatus $status): int
    {
        if ($status->isInSync()) {
            $this->info('SUCCESS: Firewall configuration is in sync with the kernel.');

            return self::SUCCESS;
        }

        if ($status->isDrift()) {
            Log::warning('Firewall sync drift detected', $status->toArray());
            $this->error('ERROR: Firewall configuration drift detected.');

            return self::FAILURE;
        }

        // Status is unknown/unverified
        if ($this->option('strict')) {
            $this->error('ERROR: Firewall sync status is unknown/unverified (strict mode).');

            return 2;
        }

        $this->warn('WARNING: Firewall sync status could not be verified (no sidecar or unreadable).');

        return self::SUCCESS;
    }
}
