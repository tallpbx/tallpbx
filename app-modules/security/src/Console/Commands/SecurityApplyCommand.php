<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Modules\Security\Contracts\FirewallApplierInterface;

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
    public function handle(FirewallApplierInterface $applier): int
    {
        $this->info('TallPBX Security: Compiling host firewall ruleset...');

        $result = $applier->apply(
            adminIp: '127.0.0.1',
            force: (bool) $this->option('force'),
            source: 'cli',
            description: 'Firewall ruleset applied via CLI command security:apply',
        );

        if ($result->failed()) {
            $this->error($result->errorMessage);

            if ($result->stage === 'lockout_guard') {
                $this->warn('Use --force to override this safety check if running strictly from the local host console.');
            }

            return self::FAILURE;
        }

        $this->info('SUCCESS: Host firewall ruleset applied atomically.');

        return self::SUCCESS;
    }
}
