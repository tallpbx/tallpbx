<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Modules\Security\Contracts\SecurityBanServiceInterface;

/**
 * Artisan command to unban an IP address across MariaDB, Redis, and Linux nftables.
 */
class SecurityUnbanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:unban
                            {ip : IPv4 or IPv6 address to unban}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lift an active ban on an IP address across MariaDB, Redis, and Linux nftables';

    /**
     * Execute the console command.
     */
    public function handle(SecurityBanServiceInterface $banService): int
    {
        $ip = trim((string) $this->argument('ip'));

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error("Invalid IP address format: '{$ip}'");

            return self::FAILURE;
        }

        $wasBanned = $banService->isBanned($ip);

        $banService->unban($ip);

        if ($wasBanned) {
            $this->info("SUCCESS: Successfully unbanned {$ip}.");
        } else {
            $this->warn("IP address {$ip} had no active database ban, but unban cleanup was executed.");
        }

        return self::SUCCESS;
    }
}
