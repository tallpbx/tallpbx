<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles appliance IP addresses across environment configuration, tenant SIP realms,
 * inbound call routes, and caches when restoring or cloning host snapshots.
 */
class ApplianceSyncHostCommand extends Command
{
    protected $signature = 'appliance:sync-host
        {--old-ip= : The previous IP address to migrate from}
        {--new-ip= : The target new IP address (defaults to current host IP or FS_DEFAULT_SIP_REALM)}';

    protected $description = 'Reconcile appliance IP addresses across database, SIP realms, and routes after snapshot restoration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $newIp = (string) ($this->option('new-ip') ?? '');

        if ($newIp === '') {
            $configuredRealm = trim((string) config('freeswitch.default_sip_realm', ''));
            if ($configuredRealm !== '' && filter_var($configuredRealm, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $newIp = $configuredRealm;
            } else {
                $detectedIp = trim((string) @shell_exec("hostname -I | awk '{print $1}'"));
                if ($detectedIp !== '' && filter_var($detectedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $newIp = $detectedIp;
                }
            }
        }

        if ($newIp === '' || ! filter_var($newIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->components->error('Unable to determine a valid IPv4 address for --new-ip.');

            return self::FAILURE;
        }

        $this->components->info("Target appliance IP: {$newIp}");

        $explicitOldIp = (string) ($this->option('old-ip') ?? '');
        $oldIps = [];

        if ($explicitOldIp !== '') {
            if (! filter_var($explicitOldIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $this->components->error("Invalid IPv4 address provided for --old-ip: {$explicitOldIp}");

                return self::FAILURE;
            }
            if ($explicitOldIp !== $newIp) {
                $oldIps[] = $explicitOldIp;
            }
        } else {
            // Auto-detect any stale IPv4 addresses in tenant_domains
            $detectedStaleIps = DB::table('tenant_domains')
                ->where('purpose', 'sip_realm')
                ->where('domain', '!=', $newIp)
                ->pluck('domain')
                ->filter(fn ($domain) => filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
                ->unique()
                ->values()
                ->all();

            $oldIps = $detectedStaleIps;
        }

        if (empty($oldIps)) {
            $this->components->info('No stale IP realms found to migrate.');
        } else {
            foreach ($oldIps as $oldIp) {
                $this->components->task("Migrating records from {$oldIp} to {$newIp}", function () use ($oldIp, $newIp): void {
                    // Reconcile the realms row by row. A snapshot restore
                    // usually leaves the tenant already owning the new address,
                    // so a blanket UPDATE collides with the
                    // (domain, purpose, tenant) unique key and aborts the
                    // installer. Move what is free and drop what is now a
                    // duplicate, so a re-run converges instead of failing.
                    $staleDomains = DB::table('tenant_domains')
                        ->where('domain', $oldIp)
                        ->get(['id', 'tenant_id', 'purpose']);

                    foreach ($staleDomains as $staleDomain) {
                        $targetExists = DB::table('tenant_domains')
                            ->where('domain', $newIp)
                            ->where('tenant_id', $staleDomain->tenant_id)
                            ->where('purpose', $staleDomain->purpose)
                            ->exists();

                        if ($targetExists) {
                            DB::table('tenant_domains')->where('id', $staleDomain->id)->delete();

                            continue;
                        }

                        DB::table('tenant_domains')
                            ->where('id', $staleDomain->id)
                            ->update(['domain' => $newIp]);
                    }

                    // Update inbound_routes action_data
                    DB::table('inbound_routes')
                        ->where('action_data', 'like', "%{$oldIp}%")
                        ->update([
                            'action_data' => DB::raw('REPLACE(action_data, '.DB::getPdo()->quote($oldIp).', '.DB::getPdo()->quote($newIp).')'),
                        ]);

                    // Update sipp-users.csv if present
                    $sippCsv = storage_path('app/load-tests/sipp-users.csv');
                    if (file_exists($sippCsv)) {
                        $csvContent = (string) file_get_contents($sippCsv);
                        if (str_contains($csvContent, $oldIp)) {
                            file_put_contents($sippCsv, str_replace($oldIp, $newIp, $csvContent));
                        }
                    }
                });
            }
        }

        // Clear application caches
        $this->components->task('Clearing compiled bootstrap cache', function (): void {
            Artisan::call('optimize:clear');
        });

        // Flush the telephony cache store only. Rendered XML is the only data
        // that embeds the old address, and a full flushall() would also empty
        // sessions (DB 2) and the CDR stream (DB 3), where losing records is
        // unacceptable.
        $this->components->task('Flushing Redis cache store', function (): void {
            try {
                Cache::store('redis')->flush();
            } catch (\Throwable) {
                // Redis may be down or unconfigured
            }
        });

        // Reload FreeSWITCH XML configuration
        $this->components->task('Reloading FreeSWITCH XML configuration', function (): void {
            if (function_exists('exec')) {
                @exec('fs_cli -x "reloadxml" 2>/dev/null');
            }
        });

        // Reconcile PHP-FPM dynamic pool ceiling based on detected physical RAM
        $this->components->task('Reconciling PHP-FPM worker pool ceiling', function (): void {
            $poolFiles = glob('/etc/php/*/fpm/pool.d/www.conf') ?: [];
            if (empty($poolFiles)) {
                return;
            }

            $totalMb = 1024;
            if (file_exists('/proc/meminfo')) {
                $content = (string) file_get_contents('/proc/meminfo');
                if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $content, $matches)) {
                    $totalMb = (int) round(((int) $matches[1]) / 1024);
                }
            }

            $targetMaxChildren = match (true) {
                $totalMb >= 3500 => 12,
                $totalMb >= 1800 => 6,
                default => 3,
            };

            $reloaded = false;
            foreach ($poolFiles as $poolFile) {
                if (! is_writable($poolFile)) {
                    continue;
                }

                $content = (string) file_get_contents($poolFile);
                if (preg_match('/^[; ]*pm\.max_children\s*=\s*(\d+)/m', $content, $matches)) {
                    $current = (int) $matches[1];
                    if ($current !== $targetMaxChildren) {
                        $updated = preg_replace('/^[; ]*pm\.max_children\s*=\s*.*/m', "pm.max_children = {$targetMaxChildren}", $content);
                        if ($updated !== null && $updated !== $content) {
                            file_put_contents($poolFile, $updated);
                            $reloaded = true;
                        }
                    }
                }
            }

            if ($reloaded && function_exists('exec')) {
                @exec('systemctl reload php8.5-fpm 2>/dev/null || systemctl restart php8.5-fpm 2>/dev/null');
            }
        });

        $this->components->info('Appliance host synchronization complete.');

        return self::SUCCESS;
    }
}
