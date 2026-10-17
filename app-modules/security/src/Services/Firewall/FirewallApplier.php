<?php

declare(strict_types=1);

namespace Modules\Security\Services\Firewall;

use Modules\Security\Contracts\FirewallApplierInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Events\FirewallRulesetUpdated;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Domain service coordinating the safety-critical 5-step firewall apply pipeline.
 *
 * Pipeline steps:
 * 1. Zero-lockout preflight validation (caller IP & server loopback local services).
 * 2. Generate and write pending configuration to disk.
 * 3. Validate syntax of pending ruleset via nft -c.
 * 4. Atomically apply ruleset to running Linux kernel via bounded sudoers helper.
 * 5. Record enterprise audit log, reset pending changes count, and broadcast real-time update.
 */
class FirewallApplier implements FirewallApplierInterface
{
    /**
     * Create a new FirewallApplier domain service instance.
     */
    public function __construct(
        protected LockoutGuardService $lockoutGuard,
        protected SecurityConfigGenerator $generator,
        protected SecurityExecutorInterface $executor,
    ) {}

    /**
     * Compile, validate, and atomically apply the host firewall ruleset to the Linux kernel.
     *
     * @param  string|null  $adminIp  The administrator or caller IP to verify against lockout
     * @param  string|null  $policy  Optional expected default chain policy ('drop' or 'accept')
     * @param  bool  $force  If true, bypass zero-lockout preflight checks
     * @param  string  $source  Invocation origin ('ui', 'cli', 'boot_recovery')
     * @param  int|null  $adminId  Optional ID of the administrator performing the action
     * @param  string|null  $description  Custom audit log description
     * @param  bool  $dryRun  If true, validate and generate but do not execute kernel apply or audit log
     * @param  bool  $resetPendingChanges  Whether to reset pending_changes_count on success
     * @param  string|null  $action  Optional custom audit log action identifier
     */
    public function apply(
        ?string $adminIp = null,
        ?string $policy = null,
        bool $force = false,
        string $source = 'ui',
        ?int $adminId = null,
        ?string $description = null,
        bool $dryRun = false,
        bool $resetPendingChanges = true,
        ?string $action = null,
    ): FirewallApplyResult {
        // Step 1: Zero-lockout preflight validation
        if (! $force) {
            try {
                if ($adminIp !== null && $adminIp !== '') {
                    if ($policy !== null) {
                        $this->lockoutGuard->assertSafe($adminIp, $policy);
                    } else {
                        $this->lockoutGuard->assertSafe($adminIp);
                    }
                }

                $this->lockoutGuard->assertLocalServicesSafe();
            } catch (LockoutException $e) {
                return FirewallApplyResult::failure(
                    errorMessage: $e->getMessage(),
                    stage: 'lockout_guard',
                );
            }
        }

        // Step 2: Write pending configuration
        try {
            $pendingFile = $this->generator->writePending();
        } catch (\Throwable $e) {
            return FirewallApplyResult::failure(
                errorMessage: "Failed to write pending configuration: {$e->getMessage()}",
                stage: 'generator',
            );
        }

        // Step 3: Preflight syntax check via nft -c
        if (! $this->generator->validateSyntax($pendingFile)) {
            return FirewallApplyResult::failure(
                errorMessage: 'Pending firewall configuration failed nftables syntax validation. Kernel ruleset was not modified.',
                stage: 'syntax_validation',
                pendingFile: $pendingFile,
            );
        }

        // Step 4: Apply via bounded host helper
        if (! $dryRun) {
            if (! $this->executor->apply()) {
                return FirewallApplyResult::failure(
                    errorMessage: 'Failed to apply firewall ruleset via bounded helper.',
                    stage: 'executor',
                    pendingFile: $pendingFile,
                );
            }
        }

        // Step 5: Post-apply side effects (audit log, pending changes, event broadcast)
        if (! $dryRun) {
            $auditAction = $action ?? match ($source) {
                'cli' => 'firewall_applied_cli',
                'boot_recovery' => 'firewall_boot_recovered',
                default => 'firewall_applied_ui',
            };

            $auditDescription = $description ?? match ($source) {
                'cli' => 'Firewall ruleset applied via CLI command security:apply',
                'boot_recovery' => 'Automated boot recovery applied saved firewall ruleset to Linux kernel',
                default => 'Firewall ruleset applied automatically from Security Command Center UI',
            };

            SecurityAuditLog::record(
                action: $auditAction,
                ipAddress: $adminIp,
                description: $auditDescription,
                adminId: $adminId,
            );

            if ($resetPendingChanges) {
                SecuritySetting::updateOrCreate(['key' => 'pending_changes_count'], ['value' => '0']);
            }

            FirewallRulesetUpdated::dispatch($source);
        }

        return FirewallApplyResult::success(
            pendingFile: $pendingFile,
            auditLogged: ! $dryRun,
            broadcast: ! $dryRun,
        );
    }
}
