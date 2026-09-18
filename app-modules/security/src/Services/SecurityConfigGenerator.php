<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Illuminate\Support\Facades\Log;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Symfony\Component\Process\Process;

/**
 * Service that compiles Linux nftables packet filtering configurations.
 *
 * Translates MariaDB security state (whitelists, blacklists, active bans, PBX port
 * catalog services, and sequential rules) into high-performance native nftables rulesets.
 */
class SecurityConfigGenerator
{
    /**
     * Directory path where TallPBX firewall configuration files are stored.
     */
    private string $firewallDir;

    /**
     * Path to the pending firewall ruleset file.
     */
    private string $pendingFile;

    /**
     * Path to the active firewall ruleset file.
     */
    private string $activeFile;

    /**
     * Create the configuration generator instance.
     *
     * @param  string|null  $firewallDir  Optional directory override (defaults to /etc/tallpbx)
     */
    public function __construct(?string $firewallDir = null)
    {
        $this->firewallDir = rtrim($firewallDir ?? '/etc/tallpbx', '/');
        $this->pendingFile = "{$this->firewallDir}/firewall.nft.pending";
        $this->activeFile = "{$this->firewallDir}/firewall.nft";
    }

    /**
     * Generate the complete nftables ruleset text based on current database state.
     */
    public function generate(): string
    {
        $firewallEnabled = SecuritySetting::getBoolean('firewall_enabled', true);
        $defaultPolicy = strtolower(trim((string) SecuritySetting::get('firewall_default_policy', 'drop')));
        if (! in_array($defaultPolicy, ['drop', 'accept'], true)) {
            $defaultPolicy = 'drop';
        }

        // 1. Compile IP sets
        $blacklistIps = SecurityIpList::blacklist()->pluck('ip_address')->all();
        $whitelistIps = SecurityIpList::whitelist()->pluck('ip_address')->all();

        // Ensure 127.0.0.1 is always present in the whitelist set
        if (! in_array('127.0.0.1', $whitelistIps, true)) {
            array_unshift($whitelistIps, '127.0.0.1');
        }

        $activeBans = SecurityBan::active()->get();

        $lines = [];
        $lines[] = '#!/usr/sbin/nft -f';
        $lines[] = '';
        $lines[] = '# Clear previous ruleset atomically';
        $lines[] = 'flush ruleset';
        $lines[] = '';
        $lines[] = 'table inet tallpbx_filter {';

        // 1. Blacklist Set
        $lines[] = '    # 1. Permanent Blacklist Set (Kernel interval tree for IPs & CIDRs)';
        $lines[] = '    set blacklist_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        if (! empty($blacklistIps)) {
            $elementsStr = implode(', ', $blacklistIps);
            $lines[] = "        elements = { {$elementsStr} }";
        }
        $lines[] = '    }';
        $lines[] = '';

        // 2. Dynamic Auto-Banned Set
        $lines[] = '    # 2. Dynamic Auto-Banned Set (with automatic kernel timeouts)';
        $lines[] = '    set banned_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags timeout';
        if ($activeBans->isNotEmpty()) {
            $banElements = [];
            foreach ($activeBans as $ban) {
                $seconds = $ban->timeRemaining();
                if ($seconds === null || $seconds <= 0) {
                    $banElements[] = $ban->ip_address;
                } else {
                    $banElements[] = "{$ban->ip_address} timeout {$seconds}s";
                }
            }
            $elementsStr = implode(', ', $banElements);
            $lines[] = "        elements = { {$elementsStr} }";
        }
        $lines[] = '    }';
        $lines[] = '';

        // 3. Whitelist Set
        $lines[] = '    # 3. Permanent Whitelist Set (Immune to drops & bans)';
        $lines[] = '    set whitelist_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        $elementsStr = implode(', ', $whitelistIps);
        $lines[] = "        elements = { {$elementsStr} }";
        $lines[] = '    }';
        $lines[] = '';

        // 4. Chain Input
        $chainPolicy = $firewallEnabled ? $defaultPolicy : 'accept';
        $lines[] = '    chain input {';
        $lines[] = "        type filter hook input priority -10; policy {$chainPolicy};";
        $lines[] = '';

        if (! $firewallEnabled) {
            $lines[] = '        # Firewall is currently disabled - allowing all traffic';
            $lines[] = '        iif "lo" accept';
            $lines[] = '        ct state established,related accept';
            $lines[] = '        accept';
            $lines[] = '    }';
        } else {
            $lines[] = '        # STEP 1: DROP BLACKLISTED NETWORKS & IPs IMMEDIATELY';
            $lines[] = '        ip saddr @blacklist_ips drop';
            $lines[] = '';
            $lines[] = '        # STEP 2: DROP TEMPORARILY BANNED BRUTE-FORCE ATTACKERS';
            $lines[] = '        ip saddr @banned_ips drop';
            $lines[] = '';
            $lines[] = '        # STEP 3: BASE INVARIANTS: LOOPBACK & ESTABLISHED CONNECTIONS';
            $lines[] = '        iif "lo" accept';
            $lines[] = '        ct state established,related accept';
            $lines[] = '        ct state invalid drop';
            $lines[] = '';
            $lines[] = '        # STEP 4: ACCEPT WHITELISTED / TRUSTED IPs UNCONDITIONALLY';
            $lines[] = '        ip saddr @whitelist_ips accept';
            $lines[] = '';
            $lines[] = '        # STEP 5: ICMP (Ping) & ICMPv6 (Neighbor Discovery)';
            $lines[] = '        ip protocol icmp icmp type echo-request accept';
            $lines[] = '        ip6 nexthdr icmpv6 accept';
            $lines[] = '';

            // Step 6: System PBX services from port catalog
            $lines[] = '        # STEP 6: CORE PBX TELEPHONY PORTS';
            $systemServices = SecurityService::system()->get();
            foreach ($systemServices as $service) {
                $lines[] = "        # Service: {$service->name}";
                $formattedPort = $this->formatPortRange($service->port_range);
                foreach ($this->generateServiceRules($service->protocol, $formattedPort, 'accept') as $ruleLine) {
                    $lines[] = "        {$ruleLine}";
                }
            }
            $lines[] = '';

            // Step 7: Custom sequential rules
            $lines[] = '        # STEP 7: CUSTOM SEQUENTIAL RULES';
            $customRules = SecurityRule::ordered()->active()->with('service')->get();
            foreach ($customRules as $rule) {
                $lines[] = "        # Rule {$rule->sequence}: {$rule->description}";
                $prefix = '';
                $source = trim($rule->source_ip);
                if ($source !== '' && $source !== 'any' && $source !== '0.0.0.0/0') {
                    $prefix = "ip saddr {$source} ";
                }

                $action = (strtolower($rule->action) === 'drop' || strtolower($rule->action) === 'block') ? 'drop' : 'accept';

                if ($rule->service !== null) {
                    $proto = $rule->service->protocol;
                    $rawPort = $rule->service->port_range;
                } else {
                    $proto = $rule->custom_protocol ?? 'tcp';
                    $rawPort = $rule->custom_port ?? '';
                }

                $formattedPort = $this->formatPortRange($rawPort);
                foreach ($this->generateServiceRules($proto, $formattedPort, $action, $prefix) as $ruleLine) {
                    $lines[] = "        {$ruleLine}";
                }
            }
            $lines[] = '';

            // Step 8: Default policy enforcement
            $lines[] = '        # STEP 8: DEFAULT INBOUND POLICY';
            $lines[] = "        {$defaultPolicy}";
            $lines[] = '    }';
        }

        $lines[] = '';
        $lines[] = '    chain forward {';
        $lines[] = '        type filter hook forward priority filter; policy drop;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    chain output {';
        $lines[] = '        type filter hook output priority filter; policy accept;';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Write the generated ruleset to the pending file for atomic validation.
     *
     * @param  string|null  $path  Custom destination path override
     */
    public function writePending(?string $path = null): string
    {
        $target = $path ?? $this->pendingFile;
        $content = $this->generate();

        $dir = dirname($target);
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        file_put_contents($target, $content);
        @chmod($target, 0640);

        return $target;
    }

    /**
     * Write the generated ruleset directly to the active configuration file.
     *
     * @param  string|null  $path  Custom destination path override
     */
    public function writeActive(?string $path = null): string
    {
        $target = $path ?? $this->activeFile;
        $content = $this->generate();

        $dir = dirname($target);
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        file_put_contents($target, $content);
        @chmod($target, 0640);

        return $target;
    }

    /**
     * Validate ruleset syntax using the host nft utility ('nft -c -f').
     *
     * @param  string  $filePath  Path to the configuration file to check
     */
    public function validateSyntax(string $filePath): bool
    {
        if (! file_exists($filePath)) {
            return false;
        }

        $process = new Process(['/usr/sbin/nft', '-c', '-f', $filePath]);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('nftables syntax validation error', [
                'file' => $filePath,
                'error' => $process->getErrorOutput(),
                'exit_code' => $process->getExitCode(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Format port ranges and multiple ports for nftables syntax.
     *
     * Converts colon ranges (16384:32768) to hyphens (16384-32768) and comma-separated
     * port lists (80,443) to nftables braces ({ 80, 443 }).
     *
     * @param  string  $rawPort  Raw port string
     */
    public function formatPortRange(string $rawPort): string
    {
        $port = trim($rawPort);

        if ($port === '') {
            return '';
        }

        // Colon range: 16384:32768 -> 16384-32768
        if (str_contains($port, ':')) {
            return str_replace(':', '-', $port);
        }

        // Comma-separated: 5060,5061,5080 -> { 5060, 5061, 5080 }
        if (str_contains($port, ',')) {
            $parts = array_filter(array_map('trim', explode(',', $port)));

            return '{ '.implode(', ', $parts).' }';
        }

        return $port;
    }

    /**
     * Generate nftables rules for a specific protocol and port specification.
     *
     * @param  string  $protocol  'tcp', 'udp', 'both', or 'icmp'
     * @param  string  $portFormatted  Formatted port string
     * @param  string  $action  'accept' or 'drop'
     * @param  string  $prefix  Optional prefix (e.g. 'ip saddr 10.0.0.0/24 ')
     * @return array<int, string> Generated rule lines
     */
    public function generateServiceRules(
        string $protocol,
        string $portFormatted,
        string $action = 'accept',
        string $prefix = '',
    ): array {
        $lines = [];
        $proto = strtolower(trim($protocol));
        $portSuffix = $portFormatted !== '' ? "dport {$portFormatted} " : '';

        if ($proto === 'both') {
            $lines[] = "{$prefix}udp {$portSuffix}{$action}";
            $lines[] = "{$prefix}tcp {$portSuffix}{$action}";
        } elseif ($proto === 'udp') {
            $lines[] = "{$prefix}udp {$portSuffix}{$action}";
        } elseif ($proto === 'tcp') {
            $lines[] = "{$prefix}tcp {$portSuffix}{$action}";
        } elseif ($proto === 'icmp') {
            $lines[] = "{$prefix}ip protocol icmp icmp type echo-request {$action}";
        } else {
            $lines[] = "{$prefix}tcp {$portSuffix}{$action}";
        }

        return $lines;
    }
}
