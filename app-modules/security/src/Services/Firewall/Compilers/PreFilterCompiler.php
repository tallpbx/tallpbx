<?php

declare(strict_types=1);

namespace Modules\Security\Services\Firewall\Compilers;

use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Support\AddressFamily;

/**
 * Compiles pre-filter stages (stages 1–7), ICMP diagnostics (stage 8),
 * and TFTP defense profile (stage 9).
 */
class PreFilterCompiler
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
     * Create a new PreFilterCompiler instance.
     */
    public function __construct(
        protected BlocklistCompiler $blocklistCompiler = new BlocklistCompiler,
    ) {}

    /**
     * Compile TFTP flood meter sets.
     *
     * @return array<int, string>
     */
    public function compileTftpSets(bool $tftpDefenseEnabled): array
    {
        if (! $tftpDefenseEnabled) {
            return [];
        }

        return [
            '    # 9. TFTP Flood Meter (IPv4, bounded: idle sources age out)',
            '    set tftp_flood4 {',
            '        type ipv4_addr',
            '        flags dynamic,timeout',
            '        timeout 1m',
            '        size 65535',
            '    }',
            '',
            '    # 10. TFTP Flood Meter (IPv6, mirrors set tftp_flood4)',
            '    set tftp_flood6 {',
            '        type ipv6_addr',
            '        flags dynamic,timeout',
            '        timeout 1m',
            '        size 65535',
            '    }',
            '',
        ];
    }

    /**
     * Compile sequential pre-filter stages 1–7 in their evaluation order.
     *
     * @param  array<int, string>|null  $order  Optional explicit stage order override
     * @return array<int, string>
     */
    public function compileStages(bool $observeMode, ?array $order = null): array
    {
        $order ??= $this->preFilterOrder();
        $stageLines = $this->preFilterStageLines($observeMode);
        $lines = [];

        foreach ($order as $position => $stageKey) {
            if (! isset($stageLines[$stageKey])) {
                continue;
            }

            foreach ($stageLines[$stageKey] as $stageLine) {
                // Stage numbers follow the actual evaluation order so
                // a reordered build renumbers its comments honestly.
                $lines[] = str_replace('{n}', (string) ($position + 1), $stageLine);
            }
        }

        return $lines;
    }

    /**
     * Compile STAGE 8: ICMP Ping Diagnostics and IPv6 connectivity invariants.
     *
     * @return array<int, string>
     */
    public function compileIcmpStage(?SecurityService $icmpService = null): array
    {
        $icmpService ??= SecurityService::system()->where('protocol', 'icmp')->first();
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

        $lines = [];
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

        return $lines;
    }

    /**
     * Compile STAGE 9: Hardened TFTP defense profile.
     *
     * @param  array{rate_limit: int, burst: int}|null  $tftpLimits
     * @return array<int, string>
     */
    public function compileTftpStage(bool $tftpDefenseEnabled, bool $observeMode, ?array $tftpLimits = null): array
    {
        if (! $tftpDefenseEnabled) {
            return [];
        }

        $tftpLimits ??= self::tftpLimits();
        $lines = [];
        $lines[] = '        # STAGE 9: HARDENED TFTP DEFENSE PROFILE (BEFORE THE PORT CATALOG ON PURPOSE)';
        $tftpVerdict = $this->blocklistCompiler->dropStatement('tftp', $observeMode, true);

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

        return $lines;
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
    public function preFilterStageLines(bool $observeMode): array
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
                '        ct state invalid '.$this->blocklistCompiler->dropStatement('invalid', $observeMode),
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
                '        ip saddr @blacklist_ips '.$this->blocklistCompiler->dropStatement('blacklist', $observeMode),
                '        ip6 saddr @blacklist_ips6 '.$this->blocklistCompiler->dropStatement('blacklist', $observeMode),
                '',
            ],
            'banned' => [
                '        # STAGE {n}: DROP TEMPORARILY BANNED BRUTE-FORCE ATTACKERS',
                '        ip saddr @banned_ips '.$this->blocklistCompiler->dropStatement('bans', $observeMode),
                '        ip6 saddr @banned_ips6 '.$this->blocklistCompiler->dropStatement('bans', $observeMode),
                '',
            ],
            // The counters feed the Security Center's "packets dropped by
            // the feed" metric; the sets stay populated across rebuilds.
            'threat_feeds' => [
                '        # STAGE {n}: DROP AUTOMATED PUBLIC THREAT FEED MATCHES',
                '        ip saddr @external_blocklist_ips '.$this->blocklistCompiler->dropStatement('threat_feeds', $observeMode, true),
                '        ip6 saddr @external_blocklist_ips6 '.$this->blocklistCompiler->dropStatement('threat_feeds', $observeMode, true),
                '',
            ],
        ];
    }

    /**
     * The administrator-facing TFTP flood limits, clamped to sane bounds.
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
     * @return array<int, string>
     */
    public function tftpReadPatterns(): array
    {
        $raw = SecuritySetting::get('tftp_defense_custom_patterns', '[]');
        $decoded = json_decode((string) $raw, true);

        $custom = is_array($decoded)
            ? array_values(array_filter($decoded, static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== ''))
            : [];

        return array_merge(self::TFTP_BASE_READ_PATTERNS, $custom);
    }
}
