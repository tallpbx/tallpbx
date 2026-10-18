<?php

declare(strict_types=1);

namespace Modules\Security\Services\Firewall\Compilers;

use Illuminate\Database\Eloquent\Collection;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Support\AddressFamily;

/**
 * Compiles nftables set declarations for permanent IP lists, dynamic bans,
 * external threat feeds, and geo-blocking perimeters.
 */
class BlocklistCompiler
{
    /**
     * Compile all nftables set definitions for the tallpbx_filter table.
     *
     * @return array<int, string>
     */
    public function compileSets(
        bool $blacklistEnabled,
        bool $whitelistEnabled,
        bool $attackProtectionEnabled,
    ): array {
        $blacklistIps = $blacklistEnabled ? SecurityIpList::blacklist()->orderBy('ip_address')->pluck('ip_address')->all() : [];
        $whitelistIps = $whitelistEnabled ? SecurityIpList::whitelist()->orderBy('ip_address')->pluck('ip_address')->all() : [];
        $activeBans = $attackProtectionEnabled ? SecurityBan::active()->get() : collect();

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

        // 7. Automated Public External Blocklist Set (IPv4 interval tree). Declared
        //    empty here and populated exclusively by external_blocklist.nft, so feed
        //    churn can never change the canonical ruleset digest.
        $lines[] = '    # 7. Automated Public External Blocklist Set (populated by external_blocklist.nft)';
        $lines[] = '    set external_blocklist_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        $lines[] = '    }';
        $lines[] = '';

        // 8. Automated Public External Blocklist Set (IPv6, mirrors set external_blocklist_ips)
        $lines[] = '    # 8. IPv6 Automated Public External Blocklist Set (mirrors set external_blocklist_ips)';
        $lines[] = '    set external_blocklist_ips6 {';
        $lines[] = '        type ipv6_addr';
        $lines[] = '        flags interval';
        $lines[] = '    }';
        $lines[] = '';

        // Geo-blocking sets (populated by external_blocklist.nft)
        $lines[] = '    # Dedicated Geo-Blocking Sets';
        $lines[] = '    set geo_blocked_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    set geo_blocked_ips6 {';
        $lines[] = '        type ipv6_addr';
        $lines[] = '        flags interval';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    set geo_allowed_ips {';
        $lines[] = '        type ipv4_addr';
        $lines[] = '        flags interval';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    set geo_allowed_ips6 {';
        $lines[] = '        type ipv6_addr';
        $lines[] = '        flags interval';
        $lines[] = '    }';
        $lines[] = '';

        return $lines;
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
    public function assertCompilableEntries(array $blacklistIps, array $whitelistIps, \Illuminate\Support\Collection|iterable $activeBans): void
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
    public function entriesForFamily(array $entries, string $family): array
    {
        return array_values(array_filter(
            $entries,
            fn (string $entry): bool => AddressFamily::classify($entry) === $family
        ));
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
    public function dropStatement(string $stage, bool $observeMode, bool $withCounter = false): string
    {
        if ($observeMode) {
            return 'counter log prefix "tallpbx-observe:'.$stage.' " limit rate 100/minute';
        }

        return $withCounter ? 'counter drop' : 'drop';
    }
}
