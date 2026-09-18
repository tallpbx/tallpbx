<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Artisan command to compile and apply the host firewall ruleset to Linux nftables.
 *
 * Runs syntax preflight validation and zero-lockout checks before atomically
 * applying the generated ruleset to the running Linux kernel.
 */
class SecurityApplyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:apply
                            {--force : Bypass zero-lockout warning checks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compile and atomically apply host firewall ruleset to Linux nftables';

    /**
     * Execute the console command.
     */
    public function handle(
        SecurityConfigGenerator $generator,
        SecurityExecutorInterface $executor,
        LockoutGuardService $lockoutGuard,
    ): int {
        $this->info('TallPBX Security: Compiling host firewall ruleset...');

        // 1. Zero-lockout preflight check (unless --force is passed)
        if (! $this->option('force')) {
            try {
                $lockoutGuard->assertSafe('127.0.0.1');
            } catch (LockoutException $e) {
                $this->error($e->getMessage());
                $this->warn('Use --force to override this safety check if running strictly from the local host console.');

                return self::FAILURE;
            }
        }

        // 2. Write pending configuration file
        try {
            $pendingFile = $generator->writePending();
        } catch (\Throwable $e) {
            $this->error("Failed to write pending firewall configuration: {$e->getMessage()}");

            return self::FAILURE;
        }

        // 3. Preflight syntax validation with nft -c
        if (! $generator->validateSyntax($pendingFile)) {
            $this->error('Pending ruleset failed nftables syntax validation. Active firewall was not modified.');

            return self::FAILURE;
        }

        // 4. Atomically apply ruleset via bounded executor
        if (! $executor->apply()) {
            $this->error('Failed to apply firewall ruleset via bounded helper.');

            return self::FAILURE;
        }

        // 5. Record enterprise audit log entry
        SecurityAuditLog::record(
            action: 'firewall_applied_cli',
            description: 'Firewall ruleset applied via CLI command security:apply',
        );

        $this->info('SUCCESS: Host firewall ruleset applied atomically.');

        return self::SUCCESS;
    }
}
