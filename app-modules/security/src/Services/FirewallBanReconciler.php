<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Modules\Security\Contracts\FirewallApplierInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Events\FirewallRulesetUpdated;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;

/**
 * Reconciles dynamic Linux kernel nftables ban sets with active MariaDB bans.
 *
 * Automatically heals drift between database-active bans and kernel sets (@banned_ips, @banned_ips6)
 * with strict safety rails (minimum TTL threshold to prevent race conditions during expiration,
 * batch action caps to prevent flap loops, and boot recovery for absent kernel tables).
 */
class FirewallBanReconciler
{
    /**
     * Minimum remaining seconds required to remediate a ban in the kernel.
     * Bans expiring sooner than this are ignored to prevent race conditions during expiration.
     */
    public const MIN_TTL_THRESHOLD = 30;

    /**
     * Maximum number of kernel mutations permitted in a single reconciliation run.
     */
    public const MAX_ACTIONS = 50;

    /**
     * Threshold in seconds beyond which kernel TTL skew triggers a re-time operation.
     */
    public const SKEW_THRESHOLD = 300;

    /**
     * The firewall ruleset applier domain service.
     */
    protected FirewallApplierInterface $applier;

    /**
     * Create the reconciler with the security executor and firewall applier.
     */
    public function __construct(
        protected SecurityExecutorInterface $executor,
        FirewallApplierInterface|LockoutGuardService|null $applier = null,
        ?SecurityConfigGenerator $generator = null,
    ) {
        $this->applier = $applier instanceof FirewallApplierInterface
            ? $applier
            : app(FirewallApplierInterface::class);
    }

    /**
     * Reconcile kernel ban sets with MariaDB and optionally perform boot recovery.
     *
     * @param  bool  $dryRun  If true, plan actions without modifying the kernel
     * @param  bool  $recoverBootOnly  If true, only perform boot recovery if table is missing
     * @return array{
     *     boot_recovered: bool,
     *     added: array<string, int>,
     *     removed: list<string>,
     *     retimed: array<string, int>,
     *     skipped: list<string>,
     *     errors: list<string>
     * }
     */
    public function reconcile(bool $dryRun = false, bool $recoverBootOnly = false): array
    {
        $result = [
            'boot_recovered' => false,
            'added' => [],
            'removed' => [],
            'retimed' => [],
            'skipped' => [],
            'errors' => [],
        ];

        // 1. Boot Recovery Check: Is the TallPBX filtering table missing from the kernel?
        $statusOutput = '';
        try {
            $statusOutput = $this->executor->status();
        } catch (\Throwable $e) {
            $result['errors'][] = "Failed to inspect kernel status: {$e->getMessage()}";
        }

        $tableAbsent = trim($statusOutput) !== '' && ! str_contains($statusOutput, 'table inet tallpbx_filter');

        if ($tableAbsent) {
            $applyResult = $this->applier->apply(
                adminIp: '127.0.0.1',
                source: 'boot_recovery',
                description: 'Automated boot recovery applied saved firewall ruleset to Linux kernel',
                dryRun: $dryRun,
            );

            if ($applyResult->failed()) {
                $errorMessage = match ($applyResult->stage) {
                    'lockout_guard' => 'Boot recovery aborted: localhost safety check failed.',
                    'syntax_validation' => 'Boot recovery aborted: pending ruleset failed syntax check.',
                    'executor' => 'Boot recovery apply failed via bounded helper.',
                    default => "Boot recovery error: {$applyResult->errorMessage}",
                };

                $result['errors'][] = $errorMessage;

                return $result;
            }

            $result['boot_recovered'] = true;

            if ($recoverBootOnly) {
                return $result;
            }
        }

        if ($recoverBootOnly) {
            return $result;
        }

        // 2. Fetch live kernel bans
        try {
            $kernelBans = $this->executor->bans();
        } catch (\Throwable $e) {
            $result['errors'][] = "Failed to query kernel bans: {$e->getMessage()}";

            return $result;
        }

        // 3. Fetch active DB bans
        $activeBans = SecurityBan::active()->get();
        $dbBanIps = [];
        $actionCount = 0;

        // 4. Identify missing or skewed DB bans
        foreach ($activeBans as $dbBan) {
            $ip = $dbBan->ip_address;
            $dbBanIps[$ip] = true;

            $remaining = $dbBan->timeRemaining();
            // If the ban has less than MIN_TTL_THRESHOLD remaining, skip to avoid race condition with kernel timeout
            if ($remaining !== null && $remaining < self::MIN_TTL_THRESHOLD) {
                $result['skipped'][] = $ip;

                continue;
            }

            $ttl = $remaining ?? 0;

            if (! isset($kernelBans[$ip])) {
                // Missing in kernel: needs addition
                if ($actionCount >= self::MAX_ACTIONS) {
                    $result['skipped'][] = $ip;

                    continue;
                }

                $result['added'][$ip] = $ttl;
                $actionCount++;

                if (! $dryRun) {
                    if ($this->executor->ban($ip, $ttl)) {
                        SecurityAuditLog::record(
                            action: 'ban_reconciled_added',
                            ipAddress: $ip,
                            description: "Automated reconciliation restored missing kernel ban for {$ip} ({$ttl}s)",
                        );
                    } else {
                        $result['errors'][] = "Failed to add kernel ban for {$ip}";
                    }
                }
            } else {
                // Present in kernel: check for significant TTL skew (> SKEW_THRESHOLD)
                $kernelExpires = $kernelBans[$ip]['expires'] ?? 0;
                if ($remaining !== null && $kernelExpires > 0) {
                    $diff = abs($remaining - $kernelExpires);
                    if ($diff > self::SKEW_THRESHOLD) {
                        if ($actionCount >= self::MAX_ACTIONS) {
                            $result['skipped'][] = $ip;

                            continue;
                        }

                        $result['retimed'][$ip] = $remaining;
                        $actionCount++;

                        if (! $dryRun) {
                            if ($this->executor->ban($ip, $remaining)) {
                                SecurityAuditLog::record(
                                    action: 'ban_reconciled_retimed',
                                    ipAddress: $ip,
                                    description: "Automated reconciliation corrected TTL skew for {$ip} (DB: {$remaining}s, Kernel: {$kernelExpires}s)",
                                );
                            } else {
                                $result['errors'][] = "Failed to re-time kernel ban for {$ip}";
                            }
                        }
                    }
                }
            }
        }

        // 5. Identify extraneous kernel bans (present in kernel but not active in DB)
        foreach ($kernelBans as $kernelIp => $kData) {
            if (! isset($dbBanIps[$kernelIp])) {
                if ($actionCount >= self::MAX_ACTIONS) {
                    $result['skipped'][] = $kernelIp;

                    continue;
                }

                $result['removed'][] = $kernelIp;
                $actionCount++;

                if (! $dryRun) {
                    if ($this->executor->unban($kernelIp)) {
                        SecurityAuditLog::record(
                            action: 'ban_reconciled_removed',
                            ipAddress: $kernelIp,
                            description: "Automated reconciliation removed extraneous kernel ban for {$kernelIp}",
                        );
                    } else {
                        $result['errors'][] = "Failed to remove extraneous kernel ban for {$kernelIp}";
                    }
                }
            }
        }

        if (! $dryRun && ($actionCount > 0 || $result['boot_recovered'])) {
            FirewallRulesetUpdated::dispatch('reconcile');
        }

        return $result;
    }
}
