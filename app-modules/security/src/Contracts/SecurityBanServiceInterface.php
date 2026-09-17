<?php

declare(strict_types=1);

namespace Modules\Security\Contracts;

use Modules\Security\Models\SecurityBan;

/**
 * Contract for managing host IP bans.
 *
 * Enforces IP bans across MariaDB database state, audit logging,
 * and the Linux kernel nftables dynamic @banned_ips set.
 */
interface SecurityBanServiceInterface
{
    /**
     * Apply a ban to an IP address.
     *
     * @param  string  $ip  IPv4 or IPv6 address to ban
     * @param  string  $vector  Attack vector: 'web_auth', 'sip_auth', 'ssh', or 'manual'
     * @param  string  $reason  Human-readable explanation of why the host was banned
     * @param  int|null  $durationSeconds  Duration in seconds, or NULL for a permanent ban
     */
    public function ban(string $ip, string $vector, string $reason, ?int $durationSeconds = null): SecurityBan;

    /**
     * Lift a ban from an IP address.
     *
     * @param  string  $ip  IPv4 or IPv6 address to unban
     * @param  int|null  $adminId  Administrator ID performing the unban if manual
     */
    public function unban(string $ip, ?int $adminId = null): bool;
}
