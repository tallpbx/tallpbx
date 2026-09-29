<?php

declare(strict_types=1);

namespace Modules\Security\Contracts;

/**
 * Contract for executing bounded kernel security operations.
 *
 * Coordinates atomic nftables ruleset application, dynamic banned_ips set updates,
 * and status queries via the bounded helper script /usr/local/sbin/tallpbx-security.
 */
interface SecurityExecutorInterface
{
    /**
     * Add an IP address to the dynamic kernel banned_ips set with a timeout.
     *
     * @param  string  $ip  IPv4 or IPv6 address to ban
     * @param  int  $durationSeconds  Ban duration in seconds (0 for permanent or default)
     */
    public function ban(string $ip, int $durationSeconds = 3600): bool;

    /**
     * Remove an IP address from the dynamic kernel banned_ips set.
     *
     * @param  string  $ip  IPv4 or IPv6 address to unban
     */
    public function unban(string $ip): bool;

    /**
     * Sever an address's live kernel sessions by flushing its conntrack entries.
     *
     * The blocklists evaluate only new flows (they sit behind the stateful
     * fast path), so an address that just entered a blocklist keeps its
     * established calls and connections until these entries are deleted.
     *
     * @param  string  $ip  IPv4 or IPv6 address whose sessions must be severed
     */
    public function flushConntrack(string $ip): bool;

    /**
     * Promote and load the pending threat feed set-element file.
     *
     * The bounded helper reads only its canonical pending file (no path
     * argument); a helper older than the capability version refuses with a
     * logged plain-language error instead of an opaque usage message.
     */
    public function updateThreatFeed(): bool;

    /**
     * Atomically compile and apply pending nftables ruleset.
     */
    public function apply(): bool;

    /**
     * Query the active kernel nftables ruleset status.
     */
    public function status(): string;

    /**
     * Query active dynamic kernel ban sets in structured format.
     *
     * @return array<string, array{ip: string, timeout: int, expires: int, family: string}> Keyed by IP address
     */
    public function bans(): array;
}
