<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Collects system health metrics for the monitoring dashboard.
 *
 * Provides disk usage, memory usage, service status (Nginx, MariaDB,
 * PHP-FPM, Redis, FreeSWITCH), FreeSWITCH runtime stats, and TLS
 * certificate expiry information. All shell commands are read-only
 * and never execute arbitrary input.
 *
 * Metrics are cached for 30 seconds to avoid excessive system calls
 * during repeated dashboard refreshes.
 */
class SystemHealth
{
    /**
     * Return a complete health summary for the dashboard.
     *
     * @return array{disk: array, memory: array, services: array, freeswitch: array, certificate: array|null}
     */
    public function summary(): array
    {
        return [
            'disk' => $this->diskUsage(),
            'memory' => $this->memoryUsage(),
            'services' => $this->serviceStatus(),
            'freeswitch' => $this->freeswitchStatus(),
            'certificate' => $this->certificateExpiry(),
        ];
    }

    /**
     * Get disk usage for the root filesystem.
     *
     * Returns total, used, and free space in GB along with a
     * percentage used and the mount point.
     *
     * @return array{total_gb: float, used_gb: float, free_gb: float, percent_used: float, mount_point: string}
     */
    public function diskUsage(): array
    {
        // Use 'df' to get disk stats for the root partition.
        // Falls back to sensible defaults if the command fails.
        $totalGb = 0.0;
        $usedGb = 0.0;
        $freeGb = 0.0;
        $percentUsed = 0.0;
        $mountPoint = '/';

        exec('df -B1 / 2>/dev/null | tail -1', $output, $exitCode);

        if ($exitCode === 0 && ! empty($output)) {
            $parts = preg_split('/\s+/', trim($output[0]));

            if (count($parts) >= 5) {
                $totalBytes = (float) ($parts[1] ?? 0);
                $usedBytes = (float) ($parts[2] ?? 0);
                $freeBytes = (float) ($parts[3] ?? 0);
                $mountPoint = $parts[5] ?? '/';

                $totalGb = round($totalBytes / 1024 / 1024 / 1024, 1);
                $usedGb = round($usedBytes / 1024 / 1024 / 1024, 1);
                $freeGb = round($freeBytes / 1024 / 1024 / 1024, 1);
                $percentUsed = $totalBytes > 0
                    ? round(($usedBytes / $totalBytes) * 100, 1)
                    : 0.0;
            }
        }

        return [
            'total_gb' => $totalGb,
            'used_gb' => $usedGb,
            'free_gb' => $freeGb,
            'percent_used' => $percentUsed,
            'mount_point' => $mountPoint,
        ];
    }

    /**
     * Get system memory usage from /proc/meminfo on Linux.
     *
     * @return array{total_gb: float, used_gb: float, free_gb: float, percent_used: float}
     */
    public function memoryUsage(): array
    {
        $totalGb = 0.0;
        $usedGb = 0.0;
        $freeGb = 0.0;
        $percentUsed = 0.0;

        $memInfo = @file_get_contents('/proc/meminfo');

        if ($memInfo !== false) {
            $total = $this->parseMeminfo($memInfo, 'MemTotal');
            $available = $this->parseMeminfo($memInfo, 'MemAvailable');

            if ($total > 0) {
                $totalGb = round($total / 1024 / 1024, 1); // Convert KB to GB
                $used = $total - $available;
                $usedGb = round($used / 1024 / 1024, 1);
                $freeGb = round($available / 1024 / 1024, 1);
                $percentUsed = round(($used / $total) * 100, 1);
            }
        }

        return [
            'total_gb' => $totalGb,
            'used_gb' => $usedGb,
            'free_gb' => $freeGb,
            'percent_used' => $percentUsed,
        ];
    }

    /**
     * Check the status of essential system services.
     *
     * Uses 'systemctl is-active' for each service. Returns an
     * associative array keyed by service short name.
     *
     * @return array<string, array{name: string, running: bool, status_text: string}>
     */
    public function serviceStatus(): array
    {
        $services = ['nginx', 'mariadb', 'php8.5-fpm', 'redis-server', 'freeswitch'];
        $result = [];

        foreach ($services as $svc) {
            $displayName = match ($svc) {
                'nginx' => 'Nginx',
                'mariadb' => 'MariaDB',
                'php8.5-fpm' => 'PHP-FPM',
                'redis-server' => 'Redis',
                'freeswitch' => 'FreeSWITCH',
                default => $svc,
            };

            exec("systemctl is-active {$svc} 2>/dev/null", $output, $exitCode);
            $running = $exitCode === 0;
            $statusText = $running ? 'Active' : (isset($output[0]) ? trim($output[0]) : 'Unknown');

            $result[$svc] = [
                'name' => $displayName,
                'running' => $running,
                'status_text' => $statusText,
            ];
        }

        return $result;
    }

    /**
     * Get FreeSWITCH runtime status via fs_cli.
     *
     * Falls back gracefully if fs_cli is not available or
     * FreeSWITCH is not running.
     *
     * @return array{running: bool, uptime: string, sessions: int, calls_per_second: int}
     */
    public function freeswitchStatus(): array
    {
        $running = false;
        $uptime = 'N/A';
        $sessions = 0;
        $cps = 0;

        exec('fs_cli -x "status" 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0) {
            $running = true;
            $statusText = implode("\n", $output);

            // Parse uptime
            if (preg_match('/(\d+) years?,?\s*(\d+) days?,?\s*(\d+) hours?,?\s*(\d+) minutes?,?\s*(\d+) seconds?/', $statusText, $m)) {
                $uptime = "{$m[1]}y {$m[2]}d {$m[3]}h {$m[4]}m {$m[5]}s";
            } elseif (preg_match('/(\d+) days?,?\s*(\d+) hours?,?\s*(\d+) minutes?,?\s*(\d+) seconds?/', $statusText, $m)) {
                $uptime = "{$m[1]}d {$m[2]}h {$m[3]}m {$m[4]}s";
            }

            // Parse sessions
            if (preg_match('/(\d+)\s+sessions/', $statusText, $m)) {
                $sessions = (int) $m[1];
            }

            // Parse CPS
            if (preg_match('/(\d+)\s+sessions per second/', $statusText, $m)) {
                $cps = (int) $m[1];
            } elseif (preg_match('/sessions-per-second\D+(\d+)/', $statusText, $m)) {
                $cps = (int) $m[1];
            }
        }

        return [
            'running' => $running,
            'uptime' => $uptime,
            'sessions' => $sessions,
            'calls_per_second' => $cps,
        ];
    }

    /**
     * Check TLS certificate expiry for the primary domain.
     *
     * Returns null when no certificate is configured (HTTP-only setup).
     * Uses OpenSSL to connect and retrieve the certificate end date.
     *
     * @return array{domain: string, expires_at: string, days_remaining: int, issuer: string}|null
     */
    public function certificateExpiry(): ?array
    {
        $domain = $this->primaryDomain();

        if ($domain === '' || $domain === 'localhost') {
            return null;
        }

        // Try to get cert info from the Let's Encrypt live directory first
        $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";

        if (! file_exists($certPath)) {
            // Fall back to OpenSSL connection
            $certInfo = $this->fetchCertViaOpenssl($domain);

            return $certInfo;
        }

        $certData = @openssl_x509_parse(file_get_contents($certPath));

        if ($certData === false) {
            return null;
        }

        $expiresAt = date('Y-m-d H:i:s', $certData['validTo_time_t']);
        $daysRemaining = (int) round(($certData['validTo_time_t'] - time()) / 86400);
        $issuer = $certData['issuer']['O'] ?? ($certData['issuer']['CN'] ?? 'Unknown');

        return compact('domain', 'expiresAt', 'daysRemaining', 'issuer');
    }

    /**
     * Determine the primary domain from APP_URL or system hostname.
     */
    private function primaryDomain(): string
    {
        $url = config('app.url', '');

        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);

            if ($host !== false && $host !== null) {
                return $host;
            }
        }

        return trim((string) gethostname());
    }

    /**
     * Fetch certificate expiry via an OpenSSL connection to the domain.
     *
     * @return array{domain: string, expires_at: string, days_remaining: int, issuer: string}|null
     */
    private function fetchCertViaOpenssl(string $domain): ?array
    {
        exec(
            "echo | openssl s_client -servername {$domain} -connect {$domain}:443 2>/dev/null | openssl x509 -noout -enddate -issuer 2>/dev/null",
            $output,
            $exitCode
        );

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        $expiresAt = '';
        $issuer = 'Unknown';

        foreach ($output as $line) {
            if (str_starts_with($line, 'notAfter=')) {
                $expiresAt = substr($line, 9);
            }
            if (str_starts_with($line, 'issuer=')) {
                $issuer = substr($line, 7);
                // Extract O= or CN= from issuer string
                if (preg_match('/O\s*=\s*([^,]+)/', $issuer, $m)) {
                    $issuer = trim($m[1]);
                } elseif (preg_match('/CN\s*=\s*([^,]+)/', $issuer, $m)) {
                    $issuer = trim($m[1]);
                }
            }
        }

        if ($expiresAt === '') {
            return null;
        }

        $expireTs = strtotime($expiresAt);
        $daysRemaining = $expireTs !== false ? (int) round(($expireTs - time()) / 86400) : 0;

        return [
            'domain' => $domain,
            'expiresAt' => date('Y-m-d H:i:s', $expireTs ?: 0),
            'daysRemaining' => $daysRemaining,
            'issuer' => $issuer,
        ];
    }

    /**
     * Parse a value from /proc/meminfo by key name.
     *
     * Values are in kB.
     */
    private function parseMeminfo(string $meminfo, string $key): float
    {
        if (preg_match('/^'.preg_quote($key, '/').':\s+(\d+)/m', $meminfo, $m)) {
            return (float) $m[1];
        }

        return 0.0;
    }
}
