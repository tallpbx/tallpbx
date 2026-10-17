<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Services\GeoBlockingService;

/**
 * Sync Geo-Blocking country IP ranges and update kernel firewall sets.
 */
class SecuritySyncGeoIpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:sync-geoip
        {--force : Force fresh download even if cache is present}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Geo-Blocking country IP allocations and update kernel sets';

    /**
     * Execute the console command.
     */
    public function handle(GeoBlockingService $geoService): int
    {
        $rule = SecurityGeoRule::instance();

        if (! $rule->enabled) {
            $this->info('Geo-Blocking is currently disabled.');

            return self::SUCCESS;
        }

        if (empty($rule->countries)) {
            $this->warn('Geo-Blocking is enabled but no countries are selected.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        if (! $force && ! $rule->isDue()) {
            $this->line('Geo-Blocking sync is not due yet (interval: '.$rule->sync_interval.'), skipping.');

            return self::SUCCESS;
        }

        $this->info('Syncing Geo-Blocking country allocations for: '.implode(', ', $rule->countries));

        $result = $geoService->sync($rule, $force);

        if ($result['status'] === 'success') {
            $this->info("Geo-Blocking sync completed successfully. IPv4 CIDRs: {$result['v4_count']}, IPv6 CIDRs: {$result['v6_count']}.");

            return self::SUCCESS;
        }

        $this->error("Geo-Blocking sync failed: {$result['error']}");

        return self::FAILURE;
    }
}
