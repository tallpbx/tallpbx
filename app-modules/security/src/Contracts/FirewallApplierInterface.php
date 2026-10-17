<?php

declare(strict_types=1);

namespace Modules\Security\Contracts;

use Modules\Security\Services\Firewall\FirewallApplyResult;

/**
 * Contract for compiling, validating, and atomically applying host firewall rulesets.
 *
 * Coordinates the complete 5-step apply pipeline:
 * 1. Zero-lockout preflight validation (caller IP & server loopback local services).
 * 2. Generate and write pending configuration to disk.
 * 3. Validate syntax of pending ruleset via nft -c.
 * 4. Atomically apply ruleset to running Linux kernel via bounded sudoers helper.
 * 5. Record enterprise audit log, reset pending changes count, and broadcast real-time update.
 */
interface FirewallApplierInterface
{
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
    ): FirewallApplyResult;
}
