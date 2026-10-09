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
     * The recommended pre-filter evaluation order — the default the Security
     * Center resets to. These keys are a fixed vocabulary shared with the
     * `pre_filter_order` setting; administrator input is never an identifier.
     */
    public const DEFAULT_PRE_FILTER_ORDER = [
        'loopback',
        'whitelist',
        'invalid',
        'fast_path',
        'blacklist',
        'banned',
        'threat_feeds',
    ];

    /**
     * File-name prefixes the hardened TFTP defense refuses outright.
     *
     * Read requests (opcode 1) whose file name starts with one of these
     * strings are malicious probing or directory traversal. The list is
     * merged with the reserved `tftp_defense_custom_patterns` setting so a
     * future release can add administrator-defined patterns without
     * touching the emission code.
     */
    private const TFTP_BASE_READ_PATTERNS = [
        '../',
        '/x',
    ];

    /**
     * Upper bound applied to the administrator-configurable TFTP flood rate
     * limit and burst, so an extreme value cannot effectively disable the
     * flood meter.
     */
    private const TFTP_FLOOD_MAX = 10000;

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
        // The pre-filter pipeline (stages 1–7) is a single on/off unit: when
        // disabled, every built-in pre-filter stage is skipped while the rest
        // of the chain keeps running.
        $prefilterEnabled = SecuritySetting::getBoolean('prefilter_enabled', true);
        // Global observe mode: every drop verdict is emitted as a counter plus
        // a rate-limited log with no verdict, so the firewall evaluates and
        // records exactly what it would block but enforces nothing.
        $observeMode = SecuritySetting::getBoolean('firewall_observe_mode', false);
        // The hardened TFTP defense profile defaults to on. Its flood meters
        // are administrator-tunable because a single office NAT doing a
        // power-cut reboot storm can legitimately exceed the default rate.
        $tftpDefenseEnabled = SecuritySetting::getBoolean('tftp_defense_enabled', true);
        $tftpLimits = self::tftpLimits();
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
        // families is guaranteed by the STAGE 1 interface rule
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

        // 7. Automated Public Threat Feed Set (IPv4 interval tree). Declared
        //    empty here and populated exclusively by threat_feed.nft, so feed
        //    churn can never change the canonical ruleset digest.
        $lines[] = '    # 7. Automated Public Threat Feed Set (populated by threat_feed.nft)';
        $lines[] = '    set threat_feed_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        $lines[] = '    }';
        $lines[] = '';

        // 8. Automated Public Threat Feed Set (IPv6, mirrors set threat_feed_ips)
        $lines[] = '    # 8. IPv6 Automated Public Threat Feed Set (mirrors set threat_feed_ips)';
        $lines[] = '    set threat_feed_ips6 {';
        $lines[] = '        type ipv6_addr';
        $lines[] = '        flags interval';
        $lines[] = '    }';
        $lines[] = '';

        // 9/10. TFTP flood meters. These are memory-bounded dynamic sets: the
        //       `timeout` ages idle source entries out and `size` caps the
        //       table, so a spoofed-source flood cannot grow kernel memory
        //       without limit (a bare meter statement never evicts).
        if ($tftpDefenseEnabled) {
            $lines[] = '    # 9. TFTP Flood Meter (IPv4, bounded: idle sources age out)';
            $lines[] = '    set tftp_flood4 {';
            $lines[] = '        type ipv4_addr';
            $lines[] = '        flags dynamic,timeout';
            $lines[] = '        timeout 1m';
            $lines[] = '        size 65535';
            $lines[] = '    }';
            $lines[] = '';

            $lines[] = '    # 10. TFTP Flood Meter (IPv6, mirrors set tftp_flood4)';
            $lines[] = '    set tftp_flood6 {';
            $lines[] = '        type ipv6_addr';
            $lines[] = '        flags dynamic,timeout';
            $lines[] = '        timeout 1m';
            $lines[] = '        size 65535';
            $lines[] = '    }';
            $lines[] = '';
        }

        // 4. Chain Input. Observe mode is non-blocking by construction: the
        //    chain policy is forced to accept so packets that would have hit
        //    the default drop rule simply fall through.
        $chainPolicy = ($firewallEnabled && ! $observeMode) ? $defaultPolicy : 'accept';
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
            // The pre-filter pipeline: when enabled, all seven stages are emitted
            // in their stored evaluation sequence. When disabled, the whole block
            // is skipped; administrator-authored custom rules in STAGE 11 can
            // re-create any individual stage the operator still wants.
            $stageLines = $this->preFilterStageLines($observeMode);
            if ($prefilterEnabled) {
                foreach ($this->preFilterOrder() as $position => $stageKey) {
                    foreach ($stageLines[$stageKey] as $stageLine) {
                        // Stage numbers follow the actual evaluation order so
                        // a reordered build renumbers its comments honestly.
                        $lines[] = str_replace('{n}', (string) ($position + 1), $stageLine);
                    }
                }
            }

            // STAGE 8: ICMP Ping Diagnostics (Core System Service)
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

                $lines[] = '        # STAGE 8: ICMP PING DIAGNOSTICS';
                $lines[] = "        {$icmpPrefixV4}ip protocol icmp icmp type echo-request {$limitClause}accept";
                $lines[] = "        {$icmpPrefixV6}ip6 nexthdr ipv6-icmp icmpv6 type echo-request {$limitClause}accept";
            } else {
                $lines[] = '        # STAGE 8: ICMP PING DISABLED (STEALTH MODE)';
            }
            // Essential IPv6 connectivity invariant: path-MTU discovery
            // (packet-too-big), multicast listener maintenance (MLD), and
            // Neighbor Discovery all ride on ICMPv6 and must never be severed.
            // Echo requests are deliberately excluded so the ping policy above
            // governs IPv6 diagnostics symmetrically with IPv4 (rate limit,
            // stealth mode, and source restrictions all apply).
            $lines[] = '        ip6 nexthdr ipv6-icmp icmpv6 type { packet-too-big, mld-listener-query, mld-listener-report, mld-listener-done, mld2-listener-report, nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert, nd-redirect } accept';
            $lines[] = '';

            // STAGE 9: Hardened TFTP defense profile. These rules must be
            // emitted BEFORE the port catalog's `udp dport 69 accept` below —
            // a drop placed after an accept rule never fires. Deep packet
            // inspection inherently only sees new flows (established TFTP
            // transfers already passed the stateful fast path), which is
            // exactly what provisioning abuse looks like.
            if ($tftpDefenseEnabled) {
                $lines[] = '        # STAGE 9: HARDENED TFTP DEFENSE PROFILE (BEFORE THE PORT CATALOG ON PURPOSE)';
                $tftpVerdict = $this->dropStatement('tftp', $observeMode, true);

                // 1. Write requests (opcode 2) are refused outright:
                //    provisioning is strictly read-only.
                $lines[] = "        udp dport 69 @th,64,16 0x0002 {$tftpVerdict}";

                // 2/3 (+ future custom patterns): read requests (opcode 1)
                //    whose file name starts with a malicious prefix, matched
                //    at payload offset 80 bits (right after the 2-byte opcode
                //    plus the 8-byte fixed header). One comparison per
                //    pattern byte count keeps the merge point generic.
                foreach ($this->tftpReadPatterns() as $pattern) {
                    $bitLength = strlen($pattern) * 8;
                    $lines[] = "        udp dport 69 @th,64,16 0x0001 @th,80,{$bitLength} 0x".bin2hex($pattern)." {$tftpVerdict}";
                }

                // 4. Per-IP flood meters, one per address family. The meter
                //    elements live in the bounded sets declared above.
                $lines[] = "        udp dport 69 update @tftp_flood4 { ip saddr limit rate over {$tftpLimits['rate_limit']}/minute burst {$tftpLimits['burst']} packets } {$tftpVerdict}";
                $lines[] = "        udp dport 69 update @tftp_flood6 { ip6 saddr limit rate over {$tftpLimits['rate_limit']}/minute burst {$tftpLimits['burst']} packets } {$tftpVerdict}";
                $lines[] = '';
            }

            // STAGE 10: System PBX services from port catalog (excluding icmp, which is handled at STAGE 8)
            $lines[] = '        # STAGE 10: CORE PBX TELEPHONY PORTS';
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

            // STAGE 11: Custom sequential rules
            $lines[] = '        # STAGE 11: CUSTOM SEQUENTIAL RULES';
            $customRules = SecurityRule::ordered()->active()->with('service')->get();
            foreach ($customRules as $rule) {
                $lines[] = "        # Rule {$rule->sequence}: {$rule->description}";
                $prefix = '';
                $source = trim($rule->source_ip);
                if ($source !== '' && $source !== 'any' && $source !== '0.0.0.0/0') {
                    $prefix = "ip saddr {$source} ";
                }

                $action = (strtolower($rule->action) === 'drop' || strtolower($rule->action) === 'block')
                    ? $this->dropStatement('custom', $observeMode)
                    : 'accept';

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

            // STAGE 12: Default policy enforcement. While observing, the
            // effective policy is always accept — nothing is dropped.
            $lines[] = '        # STAGE 12: DEFAULT INBOUND POLICY';
            $lines[] = '        '.($observeMode ? 'accept' : $defaultPolicy);
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
            // The @ guards against a concurrent create between the check and the mkdir.
            @mkdir($dir, 0750, true);
        }

        file_put_contents($target, $content);
        // Chmod after writing so the ruleset is never world-readable, even under a permissive umask.
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
            // The @ guards against a concurrent create between the check and the mkdir.
            @mkdir($dir, 0750, true);
        }

        file_put_contents($target, $content);
        // Chmod after writing so the ruleset is never world-readable, even under a permissive umask.
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
     * Resolve the stored pre-filter order, validating it before use.
     *
     * A missing setting falls back to DEFAULT_PRE_FILTER_ORDER; a stored
     * order that violates the safety constraints refuses compilation with a
     * plain-language error instead of emitting an unsafe ruleset.
     *
     * @return array<int, string>
     */
    public function preFilterOrder(): array
    {
        $raw = SecuritySetting::get('pre_filter_order');

        if ($raw === null || trim((string) $raw) === '') {
            return self::DEFAULT_PRE_FILTER_ORDER;
        }

        $order = json_decode((string) $raw, true);

        if (! is_array($order) || $order === []) {
            throw new \RuntimeException(
                'The stored pre-filter order is not a valid list. Reset it to the recommended order in the Security Center and try again.'
            );
        }

        $order = array_values(array_map(
            static fn (mixed $entry): string => is_string($entry) ? $entry : '',
            $order
        ));
        $this->assertValidPreFilterOrder($order);

        return $order;
    }

    /**
     * Assert that a proposed pre-filter order preserves every safety rule.
     *
     * Refuses (with a precise plain-language reason) when the order is not
     * exactly the seven known stages, does not start with loopback, or places
     * any drop stage above the whitelist — the constraints that keep the
     * "you can never be locked out" guarantee while the pre-filter is on.
     *
     * @param  array<int, string>  $order  Proposed stage order
     */
    public function assertValidPreFilterOrder(array $order): void
    {
        $known = self::DEFAULT_PRE_FILTER_ORDER;
        $sortedOrder = array_values($order);
        sort($sortedOrder);
        $sortedKnown = $known;
        sort($sortedKnown);

        if ($sortedOrder !== $sortedKnown) {
            throw new \RuntimeException(
                'The pre-filter order must contain each of the seven known stages exactly once: '
                .implode(', ', $known)
                .'. Correct it in the Security Center and try again.'
            );
        }

        if ($order[0] !== 'loopback') {
            throw new \RuntimeException(
                'The pre-filter order must start with the loopback stage so localhost services can never be filtered. Correct it in the Security Center and try again.'
            );
        }

        $whitelistPosition = (int) array_search('whitelist', $order, true);
        foreach (['invalid', 'blacklist', 'banned', 'threat_feeds'] as $dropStage) {
            if ((int) array_search($dropStage, $order, true) < $whitelistPosition) {
                throw new \RuntimeException(
                    "The pre-filter order must not place the {$dropStage} stage above the whitelist: that reintroduces the administrator lockout risk the chain exists to prevent. Correct it in the Security Center and try again."
                );
            }
        }
    }

    /**
     * Build the emitted rule lines for each of the seven pre-filter stages,
     * keyed by the stage vocabulary stored in the pre-filter order.
     *
     * Stage-number placeholders ({n}) are substituted at emission time so the
     * comments always report the actual evaluation position, including after
     * an administrator reorders the stages.
     *
     * @return array<string, array<int, string>> Stage key → template lines
     */
    private function preFilterStageLines(bool $observeMode): array
    {
        return [
            // Unconditional immunity for localhost IPC (invariant 1's floor).
            'loopback' => [
                '        # STAGE {n}: BASE INVARIANT: UNCONDITIONAL LOOPBACK ACCESS',
                '        iif "lo" accept',
                '',
            ],
            // The administrator safety net: deliberately evaluated before
            // every drop rule and before the malformed-packet check.
            'whitelist' => [
                '        # STAGE {n}: ACCEPT WHITELISTED / TRUSTED IPs UNCONDITIONALLY',
                '        ip saddr @whitelist_ips accept',
                '        ip6 saddr @whitelist_ips6 accept',
                '',
            ],
            // Invalid packet defense, kept below the whitelist on purpose so
            // a trusted source is never turned away (invariant 2).
            'invalid' => [
                '        # STAGE {n}: DROP INVALID PACKETS (after the whitelist on purpose)',
                '        ct state invalid '.$this->dropStatement('invalid', $observeMode),
                '',
            ],
            // Passes the bulk of ongoing SIP/RTP media with zero set lookups.
            'fast_path' => [
                '        # STAGE {n}: STATEFUL FAST PATH (ONGOING CONNECTIONS PASS INSTANTLY)',
                '        ct state established,related accept',
                '',
            ],
            'blacklist' => [
                '        # STAGE {n}: DROP BLACKLISTED NETWORKS & IPs IMMEDIATELY',
                '        ip saddr @blacklist_ips '.$this->dropStatement('blacklist', $observeMode),
                '        ip6 saddr @blacklist_ips6 '.$this->dropStatement('blacklist', $observeMode),
                '',
            ],
            'banned' => [
                '        # STAGE {n}: DROP TEMPORARILY BANNED BRUTE-FORCE ATTACKERS',
                '        ip saddr @banned_ips '.$this->dropStatement('bans', $observeMode),
                '        ip6 saddr @banned_ips6 '.$this->dropStatement('bans', $observeMode),
                '',
            ],
            // The counters feed the Security Center's "packets dropped by
            // the feed" metric; the sets stay populated across rebuilds.
            'threat_feeds' => [
                '        # STAGE {n}: DROP AUTOMATED PUBLIC THREAT FEED MATCHES',
                '        ip saddr @threat_feed_ips '.$this->dropStatement('threat_feeds', $observeMode, true),
                '        ip6 saddr @threat_feed_ips6 '.$this->dropStatement('threat_feeds', $observeMode, true),
                '',
            ],
        ];
    }

    /**
     * Build the action statement for a drop stage.
     *
     * In the enforcing build this is a plain `drop` (or `counter drop` when
     * the stage needs a persistent packet counter). In global observe mode it
     * becomes a counter plus a rate-limited log with NO verdict, so evaluation
     * and counting still happen — exactly what *would* have been dropped — but
     * the packet is never blocked.
     *
     * @param  string  $stage  Short stage label used in the observe log prefix
     * @param  bool  $observeMode  Whether the global observe mode is on
     * @param  bool  $withCounter  Whether the enforcing build also counts packets
     */
    private function dropStatement(string $stage, bool $observeMode, bool $withCounter = false): string
    {
        if ($observeMode) {
            return 'counter log prefix "tallpbx-observe:'.$stage.' " limit rate 100/minute';
        }

        return $withCounter ? 'counter drop' : 'drop';
    }

    /**
     * The administrator-facing TFTP flood limits, clamped to sane bounds.
     *
     * Zero, negative, or missing values fall back to the recommended
     * defaults (10 requests per minute, burst 20); extreme values are capped
     * so the meter keeps meaning something. The Security Center reads the
     * same helper so the panel can never display a value the kernel is not
     * actually running.
     *
     * @return array{rate_limit: int, burst: int}
     */
    public static function tftpLimits(): array
    {
        $rateLimit = (int) SecuritySetting::get('tftp_defense_rate_limit', '10');
        $burst = (int) SecuritySetting::get('tftp_defense_burst', '20');

        if ($rateLimit <= 0) {
            $rateLimit = 10;
        }

        if ($burst <= 0) {
            $burst = 20;
        }

        return [
            'rate_limit' => min($rateLimit, self::TFTP_FLOOD_MAX),
            'burst' => min($burst, self::TFTP_FLOOD_MAX),
        ];
    }

    /**
     * The merged TFTP read-pattern list (base patterns + reserved custom).
     *
     * The reserved `tftp_defense_custom_patterns` setting ships empty and has
     * no UI or validation path yet — only this merge point exists, so the
     * eventual custom-pattern feature needs no emission changes. Entries are
     * plain byte strings converted to hexadecimal payload comparisons.
     *
     * @return array<int, string>
     */
    private function tftpReadPatterns(): array
    {
        $raw = SecuritySetting::get('tftp_defense_custom_patterns', '[]');
        $decoded = json_decode((string) $raw, true);

        $custom = is_array($decoded)
            ? array_values(array_filter($decoded, static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== ''))
            : [];

        return array_merge(self::TFTP_BASE_READ_PATTERNS, $custom);
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
