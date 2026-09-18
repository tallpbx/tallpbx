<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Events\SecurityBanUpdated;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Service that manages active and historical host IP bans.
 *
 * Coordinates state across the MariaDB security_bans table, security audit logs,
 * Redis rate limiter failure counters, and the Linux kernel nftables dynamic set.
 * Enforces zero-lockout protection by refusing to ban whitelisted IP addresses.
 */
class SecurityBanService implements SecurityBanServiceInterface
{
    /**
     * Attack vectors tracked by the intrusion detection system.
     */
    private const VECTORS = ['web_auth', 'sip_auth', 'ssh', 'manual'];

    /**
     * Create the ban service instance.
     *
     * @param  SecurityExecutorInterface|null  $executor  Optional kernel executor service
     */
    public function __construct(
        private readonly ?SecurityExecutorInterface $executor = null,
    ) {}

    /**
     * Apply a ban to an IP address.
     *
     * Refuses to ban whitelisted IPs. Creates or updates a record in security_bans,
     * logs an audit trail event, instructs the kernel to drop packets from the IP,
     * and flushes the Redis sliding-window attempt counter.
     *
     * @param  string  $ip  IPv4 or IPv6 address to ban
     * @param  string  $vector  Attack vector: 'web_auth', 'sip_auth', 'ssh', or 'manual'
     * @param  string  $reason  Human-readable explanation of why the host was banned
     * @param  int|null  $durationSeconds  Duration in seconds, or NULL for a permanent ban
     *
     * @throws \InvalidArgumentException If the IP address is whitelisted
     */
    public function ban(string $ip, string $vector, string $reason, ?int $durationSeconds = null): SecurityBan
    {
        $ip = trim($ip);

        // 1. Guard against banning whitelisted addresses
        if ($this->isWhitelisted($ip)) {
            Log::warning("Refusing to ban whitelisted IP address: {$ip}", [
                'vector' => $vector,
                'reason' => $reason,
            ]);

            throw new \InvalidArgumentException("Cannot ban whitelisted IP address: {$ip}");
        }

        $now = now();
        $expiresAt = ($durationSeconds !== null && $durationSeconds > 0)
            ? $now->copy()->addSeconds($durationSeconds)
            : null;

        // 2. Check for an existing active ban for this IP
        $ban = SecurityBan::where('ip_address', $ip)
            ->where('is_active', true)
            ->first();

        if ($ban !== null) {
            $ban->update([
                'vector' => $vector,
                'reason' => $reason,
                'attempt_count' => $ban->attempt_count + 1,
                'banned_at' => $now,
                'expires_at' => $expiresAt,
                'unbanned_at' => null,
                'unbanned_by_admin_id' => null,
            ]);
        } else {
            $ban = SecurityBan::create([
                'ip_address' => $ip,
                'vector' => $vector,
                'reason' => $reason,
                'attempt_count' => 1,
                'banned_at' => $now,
                'expires_at' => $expiresAt,
                'is_active' => true,
            ]);
        }

        // 3. Record in enterprise security audit log
        $durationLabel = $durationSeconds ? " for {$durationSeconds}s" : ' permanently';
        SecurityAuditLog::record(
            action: 'ban_created',
            ipAddress: $ip,
            description: "Banned IP {$ip} via {$vector}: {$reason}{$durationLabel}",
            details: [
                'vector' => $vector,
                'reason' => $reason,
                'duration_seconds' => $durationSeconds,
                'expires_at' => $expiresAt?->toIso8601String(),
                'attempt_count' => $ban->attempt_count,
            ],
        );

        // 4. Delegate to kernel executor to block packets
        if ($this->executor !== null) {
            try {
                $this->executor->ban($ip, $durationSeconds ?? 0);
            } catch (\Throwable $e) {
                Log::error("Failed to execute kernel ban for {$ip}: {$e->getMessage()}");
            }
        }

        // 5. Reset Redis sliding-window attempt counters for this IP
        $this->flushRedisAttempts($ip);

        // 6. Broadcast real-time WebSocket alert over Laravel Reverb
        SecurityBanUpdated::dispatch($ip, 'ban');

        return $ban;
    }

    /**
     * Lift an active ban from an IP address.
     *
     * Marks active records in security_bans as inactive, logs an audit entry,
     * removes the IP from the kernel nftables banned set, and cleans up Redis state.
     *
     * @param  string  $ip  IPv4 or IPv6 address to unban
     * @param  int|null  $adminId  Administrator ID performing the unban if manual
     */
    public function unban(string $ip, ?int $adminId = null): bool
    {
        $ip = trim($ip);

        // 1. Find all active bans for this IP address
        $activeBans = SecurityBan::where('ip_address', $ip)
            ->where('is_active', true)
            ->get();

        if ($activeBans->isEmpty()) {
            return false;
        }

        // Mark bans as inactive
        SecurityBan::where('ip_address', $ip)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'unbanned_at' => now(),
                'unbanned_by_admin_id' => $adminId,
            ]);

        // 2. Record audit log
        SecurityAuditLog::record(
            action: 'unban_executed',
            ipAddress: $ip,
            description: "Unbanned IP {$ip}",
            details: [
                'admin_id' => $adminId,
                'bans_closed' => $activeBans->count(),
            ],
            adminId: $adminId,
        );

        // 3. Delegate to kernel executor to remove from nftables banned set
        if ($this->executor !== null) {
            try {
                $this->executor->unban($ip);
            } catch (\Throwable $e) {
                Log::error("Failed to execute kernel unban for {$ip}: {$e->getMessage()}");
            }
        }

        // 4. Reset Redis attempt counters for this IP
        $this->flushRedisAttempts($ip);

        // 5. Broadcast real-time WebSocket alert over Laravel Reverb
        SecurityBanUpdated::dispatch($ip, 'unban');

        return true;
    }

    /**
     * Determine if an IP address currently has an active, non-expired ban.
     *
     * @param  string  $ip  IPv4 or IPv6 address to check
     */
    public function isBanned(string $ip): bool
    {
        return SecurityBan::active()
            ->where('ip_address', trim($ip))
            ->exists();
    }

    /**
     * Get all currently active, non-expired bans.
     *
     * @return Collection<int, SecurityBan>
     */
    public function getActiveBans(): Collection
    {
        return SecurityBan::active()
            ->orderByDesc('banned_at')
            ->get();
    }

    /**
     * Mark expired bans as inactive in the database.
     *
     * @return int Number of bans updated
     */
    public function pruneExpiredBans(): int
    {
        return SecurityBan::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['is_active' => false]);
    }

    /**
     * Determine if an IP address is covered by any trusted whitelist entry.
     *
     * Supports exact IPv4/IPv6 addresses and CIDR subnets.
     *
     * @param  string  $ip  IP address to test
     */
    public function isWhitelisted(string $ip): bool
    {
        $whitelist = SecurityIpList::whitelist()->pluck('ip_address')->all();

        if (empty($whitelist)) {
            return false;
        }

        return IpUtils::checkIp($ip, $whitelist);
    }

    /**
     * Clear Redis failure tracking keys for an IP across all attack vectors.
     *
     * @param  string  $ip  IP address to clear
     */
    private function flushRedisAttempts(string $ip): void
    {
        try {
            foreach (self::VECTORS as $vector) {
                Redis::del("tallpbx:security:attempts:{$vector}:{$ip}");
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to flush Redis security attempt keys for {$ip}: {$e->getMessage()}");
        }
    }
}
