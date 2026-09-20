<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Support\AddressFamily;
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

        // 1. Compile IP sets, kept split per address family because nftables
        //    sets are typed (ipv4_addr vs ipv6_addr) and mixing families in a
        //    single set would make the whole ruleset uncompilable.
        $blacklistIps = SecurityIpList::blacklist()->orderBy('ip_address')->pluck('ip_address')->all();
        $whitelistIps = SecurityIpList::whitelist()->orderBy('ip_address')->pluck('ip_address')->all();

        $activeBans = SecurityBan::active()->get();

        // Refuse to compile entries the kernel sets cannot represent:
        // one invalid element would make the whole ruleset uncompilable.
        $this->assertCompilableEntries($blacklistIps, $whitelistIps, $activeBans);

        // Split every entry into its address family. Loopback trust in both
        // families is guaranteed by the STEP 1 interface rule
        // (`iif "lo" accept`), not by an injected whitelist element, so the
        // kernel sets mirror the database exactly.
        $blacklistV4 = $this->entriesForFamily($blacklistIps, 'ipv4');
        $blacklistV6 = $this->entriesForFamily($blacklistIps, 'ipv6');
        $whitelistV4 = $this->entriesForFamily($whitelistIps, 'ipv4');
        $whitelistV6 = $this->entriesForFamily($whitelistIps, 'ipv6');

        // Split active bans per family into ready-to-emit elements with their
        // remaining kernel timeout seconds.
        $banElementsV4 = [];
        $banElementsV6 = [];
        foreach ($activeBans as $ban) {
            $seconds = $ban->timeRemaining();
            $element = ($seconds === null || $seconds <= 0)
                ? (string) $ban->ip_address
                : "{$ban->ip_address} timeout {$seconds}s";

            if (AddressFamily::classify((string) $ban->ip_address) === 'ipv6') {
                $banElementsV6[] = $element;
            } else {
                $banElementsV4[] = $element;
            }
        }

        $lines = [];
        $lines[] = '#!/usr/sbin/nft -f';
        $lines[] = '';
        $lines[] = '# Clear previous ruleset atomically';
        $lines[] = 'flush ruleset';
        $lines[] = '';
        $lines[] = 'table inet tallpbx_filter {';

        // 1. Blacklist Set (IPv4)
        $lines[] = '    # 1. Permanent Blacklist Set (Kernel interval tree for IPs & CIDRs)';
        $lines[] = '    set blacklist_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        if ($blacklistV4 !== []) {
            $elementsStr = implode(', ', $blacklistV4);
            $lines[] = "        elements = { {$elementsStr} }";
        }
        $lines[] = '    }';
        $lines[] = '';

        // 2. Dynamic Auto-Banned Set (IPv4)
        $lines[] = '    # 2. Dynamic Auto-Banned Set (with automatic kernel timeouts)';
        $lines[] = '    set banned_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags timeout';
        if ($banElementsV4 !== []) {
            $elementsStr = implode(', ', $banElementsV4);
            $lines[] = "        elements = { {$elementsStr} }";
        }
        $lines[] = '    }';
        $lines[] = '';

        // 3. Whitelist Set (IPv4)
        $lines[] = '    # 3. Permanent Whitelist Set (Immune to drops & bans)';
        $lines[] = '    set whitelist_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        if ($whitelistV4 !== []) {
            $elementsStr = implode(', ', $whitelistV4);
            $lines[] = "        elements = { {$elementsStr} }";
        }
        $lines[] = '    }';
        $lines[] = '';

        // 4. Blacklist Set (IPv6, mirrors set blacklist_ips)
        $lines[] = '    # 4. IPv6 Permanent Blacklist Set (mirrors set blacklist_ips)';
        $lines[] = '    set blacklist_ips6 {';
        $lines[] = '        type ipv6_addr';
        $lines[] = '        flags interval';
        if ($blacklistV6 !== []) {
            $elementsStr = implode(', ', $blacklistV6);
            $lines[] = "        elements = { {$elementsStr} }";
        }
        $lines[] = '    }';
        $lines[] = '';

        // 5. Dynamic Auto-Banned Set (IPv6, mirrors set banned_ips)
        $lines[] = '    # 5. IPv6 Dynamic Auto-Banned Set (mirrors set banned_ips)';
        $lines[] = '    set banned_ips6 {';
        $lines[] = '        type ipv6_addr';
        $lines[] = '        flags timeout';
        if ($banElementsV6 !== []) {
            $elementsStr = implode(', ', $banElementsV6);
            $lines[] = "        elements = { {$elementsStr} }";
        }
        $lines[] = '    }';
        $lines[] = '';

        // 6. Whitelist Set (IPv6, mirrors set whitelist_ips)
        $lines[] = '    # 6. IPv6 Permanent Whitelist Set (mirrors set whitelist_ips)';
        $lines[] = '    set whitelist_ips6 {';
        $lines[] = '        type ipv6_addr';
        $lines[] = '        flags interval';
        if ($whitelistV6 !== []) {
            $elementsStr = implode(', ', $whitelistV6);
            $lines[] = "        elements = { {$elementsStr} }";
        }
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
            // STEP 1: Loopback interface (unconditional immunity for localhost IPC)
            $lines[] = '        # STEP 1: BASE INVARIANT: UNCONDITIONAL LOOPBACK ACCESS';
            $lines[] = '        iif "lo" accept';
            $lines[] = '';

            // STEP 2: Drop blacklisted networks & IPs immediately (both families)
            $lines[] = '        # STEP 2: DROP BLACKLISTED NETWORKS & IPs IMMEDIATELY';
            $lines[] = '        ip saddr @blacklist_ips drop';
            $lines[] = '        ip6 saddr @blacklist_ips6 drop';
            $lines[] = '';

            // STEP 3: Drop temporarily banned brute-force attackers (both families)
            $lines[] = '        # STEP 3: DROP TEMPORARILY BANNED BRUTE-FORCE ATTACKERS';
            $lines[] = '        ip saddr @banned_ips drop';
            $lines[] = '        ip6 saddr @banned_ips6 drop';
            $lines[] = '';

            // STEP 4: Base invariants: established connections & invalid packet defense
            $lines[] = '        # STEP 4: STATEFUL CONNECTION TRACKING & PACKET DEFENSE';
            $lines[] = '        ct state established,related accept';
            $lines[] = '        ct state invalid drop';
            $lines[] = '';

            // STEP 5: Accept whitelisted / trusted IPs unconditionally (both families)
            $lines[] = '        # STEP 5: ACCEPT WHITELISTED / TRUSTED IPs UNCONDITIONALLY';
            $lines[] = '        ip saddr @whitelist_ips accept';
            $lines[] = '        ip6 saddr @whitelist_ips6 accept';
            $lines[] = '';

            // STEP 6: ICMP Ping Diagnostics (Core System Service)
            $icmpService = SecurityService::system()->where('protocol', 'icmp')->first();
            $icmpEnabled = $icmpService ? (bool) $icmpService->enabled : true;
            $icmpSource = trim((string) ($icmpService?->source_ip ?? 'any'));

            // The ping source restriction applies to the address family of the
            // value entered; the other family stays unrestricted. The 0.0.0.0/0
            // and ::/0 ranges count as "any" for their family, and malformed
            // values refuse compilation instead of silently dropping the
            // restriction.
            $icmpPrefixV4 = '';
            $icmpPrefixV6 = '';
            if ($icmpSource !== '' && $icmpSource !== 'any' && $icmpSource !== '0.0.0.0/0' && $icmpSource !== '::/0') {
                $icmpFamily = AddressFamily::classify($icmpSource);
                if ($icmpFamily === 'ipv4') {
                    $icmpPrefixV4 = "ip saddr {$icmpSource} ";
                } elseif ($icmpFamily === 'ipv6') {
                    $icmpPrefixV6 = "ip6 saddr {$icmpSource} ";
                } else {
                    throw new \RuntimeException(
                        "ICMP Ping Diagnostics source network '{$icmpSource}' is not a valid IPv4 or IPv6 address or CIDR range. Correct it in the Security Center and try again."
                    );
                }
            }

            if ($icmpEnabled) {
                $rateLimit = $icmpService?->rate_limit;
                $burst = $icmpService?->burst;
                $limitClause = '';
                if ($rateLimit !== null && $rateLimit > 0) {
                    $burstClause = ($burst !== null && $burst > 0) ? " burst {$burst} packets" : '';
                    $limitClause = "limit rate {$rateLimit}/second{$burstClause} ";
                }

                $lines[] = '        # STEP 6: ICMP PING DIAGNOSTICS';
                $lines[] = "        {$icmpPrefixV4}ip protocol icmp icmp type echo-request {$limitClause}accept";
                $lines[] = "        {$icmpPrefixV6}ip6 nexthdr ipv6-icmp icmpv6 type echo-request {$limitClause}accept";
            } else {
                $lines[] = '        # STEP 6: ICMP PING DISABLED (STEALTH MODE)';
            }
            // Essential IPv6 connectivity invariant: path-MTU discovery
            // (packet-too-big), multicast listener maintenance (MLD), and
            // Neighbor Discovery all ride on ICMPv6 and must never be severed.
            // Echo requests are deliberately excluded so the ping policy above
            // governs IPv6 diagnostics symmetrically with IPv4 (rate limit,
            // stealth mode, and source restrictions all apply).
            $lines[] = '        ip6 nexthdr ipv6-icmp icmpv6 type { packet-too-big, mld-listener-query, mld-listener-report, mld-listener-done, mld2-listener-report, nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert, nd-redirect } accept';
            $lines[] = '';

            // Step 7: System PBX services from port catalog (excluding icmp which is handled above in step 6)
            $lines[] = '        # STEP 7: CORE PBX TELEPHONY PORTS';
            $systemServices = SecurityService::system()->active()->where('protocol', '!=', 'icmp')->get();
            foreach ($systemServices as $service) {
                $lines[] = "        # Service: {$service->name}";
                $prefix = '';
                $source = trim((string) ($service->source_ip ?? 'any'));
                if ($source !== '' && $source !== 'any' && $source !== '0.0.0.0/0') {
                    $prefix = "ip saddr {$source} ";
                }

                $formattedPort = $this->formatPortRange($service->port_range);
                foreach ($this->generateServiceRules($service->protocol, $formattedPort, 'accept', $prefix) as $ruleLine) {
                    $lines[] = "        {$ruleLine}";
                }
            }
            $lines[] = '';

            // Step 8: Custom sequential rules
            $lines[] = '        # STEP 8: CUSTOM SEQUENTIAL RULES';
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

            // Step 9: Default policy enforcement
            $lines[] = '        # STEP 9: DEFAULT INBOUND POLICY';
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

        // Compute deterministic canonical ruleset digest (excluding dynamic bans)
        // and insert machine-readable provenance comment markers after the shebang.
        $canonical = $this->canonicalForm(implode("\n", $lines));
        $digest = 'sha256:'.hash('sha256', $canonical);

        array_splice($lines, 1, 0, [
            '# tallpbx-policy: '.$chainPolicy,
            '# tallpbx-digest: '.$digest,
        ]);

        return implode("\n", $lines);
    }

    /**
     * Compute the deterministic canonical SHA-256 digest of the desired ruleset.
     *
     * Extracts the `# tallpbx-digest:` marker generated from canonical ruleset content,
     * which normalizes whitespace and permanent sets while decoupling dynamic attacker bans.
     */
    public function canonicalDigest(): string
    {
        $ruleset = $this->generate();

        if (preg_match('/^# tallpbx-digest:\s*(sha256:[0-9a-f]{64})$/m', $ruleset, $matches) === 1) {
            return $matches[1];
        }

        return 'sha256:'.hash('sha256', $this->canonicalForm($ruleset));
    }

    /**
     * Convert ruleset text into its normalized canonical representation.
     *
     * 1. Strips blank lines and comment lines (including tallpbx-* markers).
     * 2. Excludes dynamic attacker ban set elements (banned_ips, banned_ips6) to
     *    prevent false-drift flapping when intrusion bans are added or decay in RAM.
     * 3. Normalizes and sorts elements inside permanent sets alphabetically.
     * 4. Trims leading and trailing whitespace on each line.
     *
     * @param  string  $content  Raw nftables ruleset text
     */
    public function canonicalForm(string $content): string
    {
        $lines = explode("\n", $content);
        $canonicalLines = [];
        $currentSet = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines and comment lines (including provenance markers)
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Track current set definition
            if (preg_match('/^\s*set\s+([a-zA-Z0-9_]+)\s*\{/', $line, $matches) === 1) {
                $currentSet = $matches[1];
            } elseif ($currentSet !== null && preg_match('/^\s*\}\s*$/', $line) === 1) {
                $currentSet = null;
            }

            // Exclude dynamic attacker bans from the ruleset digest:
            // bans are manipulated directly in RAM via `tallpbx-security ban/unban`.
            if (($currentSet === 'banned_ips' || $currentSet === 'banned_ips6') &&
                str_contains($trimmed, 'elements =')
            ) {
                continue;
            }

            // Normalize and sort elements in permanent sets
            if (preg_match('/^elements\s*=\s*\{\s*(.*?)\s*\}$/', $trimmed, $matches) === 1) {
                $elements = array_filter(
                    array_map('trim', explode(',', $matches[1])),
                    fn (string $item): bool => $item !== ''
                );

                // Strip any decaying timeout suffix if present
                $elements = array_map(
                    fn (string $item): string => (string) preg_replace('/\s+timeout\s+[0-9]+s$/', '', $item),
                    $elements
                );

                sort($elements, SORT_STRING);
                $canonicalLines[] = 'elements = { '.implode(', ', $elements).' }';

                continue;
            }

            // Normalize internal whitespace on remaining lines
            $canonicalLines[] = (string) preg_replace('/\s+/', ' ', $trimmed);
        }

        return implode("\n", $canonicalLines);
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
     * Assert that every IP list entry and active ban can be compiled into the
     * IPv4 nftables sets, refusing generation with a precise error that names
     * the offending entries instead of producing an invalid ruleset that the
     * kernel preflight would later reject without explanation.
     *
     * @param  array<int, string>  $blacklistIps
     * @param  array<int, string>  $whitelistIps
     * @param  Collection<int, SecurityBan>  $activeBans
     */
    private function assertCompilableEntries(array $blacklistIps, array $whitelistIps, $activeBans): void
    {
        $invalid = [];

        foreach (['blacklist' => $blacklistIps, 'whitelist' => $whitelistIps] as $listName => $entries) {
            foreach ($entries as $entry) {
                if (! AddressFamily::isValidAddressOrCidr($entry)) {
                    $invalid[] = "{$listName} entry '{$entry}'";
                }
            }
        }

        foreach ($activeBans as $ban) {
            if (! AddressFamily::isValidAddress((string) $ban->ip_address)) {
                $invalid[] = "ban '{$ban->ip_address}'";
            }
        }

        if ($invalid !== []) {
            throw new \RuntimeException(
                'Cannot compile the firewall ruleset — these entries are not valid IPv4 or IPv6 addresses: '
                .implode(', ', $invalid)
                .'. Correct or remove them in the Security Center and try again.'
            );
        }
    }

    /**
     * Collect the entries of a list that belong to the given address family.
     *
     * @param  array<int, string>  $entries  Address/CIDR entries from the database
     * @param  string  $family  'ipv4' or 'ipv6'
     * @return array<int, string>
     */
    private function entriesForFamily(array $entries, string $family): array
    {
        return array_values(array_filter(
            $entries,
            fn (string $entry): bool => AddressFamily::classify($entry) === $family
        ));
    }

    /**
     * Validate ruleset syntax using the host nft utility ('nft -c -f').
     *
     * Privileged processes (root CLI/cron) invoke the nft utility directly.
     * PHP-FPM web workers cannot: nftables requires CAP_NET_ADMIN even for
     * check-only runs, so the preflight is delegated to the bounded root
     * helper — which validates the same pending file this class writes.
     *
     * @param  string  $filePath  Path to the configuration file to check
     */
    public function validateSyntax(string $filePath): bool
    {
        if (! file_exists($filePath)) {
            return false;
        }

        // The helper's 'validate' action accepts no path arguments and checks
        // only the canonical pending file, so delegation is used exclusively
        // when the worker is unprivileged AND the target is that exact file.
        $isPrivileged = ! function_exists('posix_geteuid') || posix_geteuid() === 0;
        if ($isPrivileged || $filePath !== $this->pendingFile) {
            $process = new Process(['/usr/sbin/nft', '-c', '-f', $filePath]);
        } else {
            $process = new Process(['sudo', '-n', '/usr/local/sbin/tallpbx-security', 'validate']);
        }

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
