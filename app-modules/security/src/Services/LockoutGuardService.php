<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecuritySetting;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Service that guards against accidental administrator firewall lockout.
 *
 * Evaluates whether changing the host firewall default inbound policy to DROP
 * or modifying filtering rules would immediately sever the current administrator's
 * connection. Provides automatic single-click whitelisting protection.
 */
class LockoutGuardService
{
    /**
     * Determine if an IP address can safely reach the system under the proposed default policy.
     *
     * Returns true if the policy is 'accept', if the IP is loopback, if the IP is
     * covered by the Trusted whitelist, or if covered by an active explicit ACCEPT rule.
     *
     * @param  string|null  $ip  Target IP address (defaults to request client IP)
     * @param  string|null  $proposedDefaultPolicy  'drop' or 'accept' (defaults to saved setting)
     */
    public function isIpSafe(?string $ip = null, ?string $proposedDefaultPolicy = null): bool
    {
        $targetIp = trim($ip ?? request()->ip() ?? '127.0.0.1');
        $policy = strtolower(trim($proposedDefaultPolicy ?? SecuritySetting::get('firewall_default_policy', 'drop')));

        // 1. If the default policy is 'accept', incoming traffic is not dropped by default
        if ($policy === 'accept') {
            return true;
        }

        // 2. Loopback connections are always safe and unconditionally accepted
        if (in_array($targetIp, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        // 3. Check if the IP is covered by any Trusted whitelist entry
        if ($this->isWhitelisted($targetIp)) {
            return true;
        }

        // 4. Check if an explicit active ACCEPT rule specifically covers this IP address
        return $this->isCoveredByAcceptRule($targetIp);
    }

    /**
     * Assert that the given IP is safe from lockout, throwing a LockoutException if unsafe.
     *
     * @param  string|null  $ip  Target IP address
     * @param  string|null  $proposedDefaultPolicy  Proposed policy ('drop' or 'accept')
     *
     * @throws LockoutException If the IP would be locked out
     */
    public function assertSafe(?string $ip = null, ?string $proposedDefaultPolicy = null): void
    {
        $targetIp = trim($ip ?? request()->ip() ?? '127.0.0.1');

        if (! $this->isIpSafe($targetIp, $proposedDefaultPolicy)) {
            throw new LockoutException(
                "Zero-Lockout Safety Alert: Your current connection from {$targetIp} is not covered by the Trusted list or an explicit ALLOW rule. "
                .'Setting the default policy to DROP would lock you out of the server.'
            );
        }
    }

    /**
     * Automatically add an IP address to the Trusted whitelist to guarantee ongoing access.
     *
     * @param  string  $ip  IP address to add to the whitelist
     * @param  string  $description  Human-readable description for the entry
     */
    public function whitelistIp(string $ip, string $description = 'Admin session auto-whitelist'): SecurityIpList
    {
        $targetIp = trim($ip);

        $existing = SecurityIpList::whitelist()
            ->where('ip_address', $targetIp)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return SecurityIpList::create([
            'type' => 'whitelist',
            'ip_address' => $targetIp,
            'description' => $description,
        ]);
    }

    /**
     * Determine if an IP address is covered by any Trusted whitelist entry.
     *
     * Supports both single IP addresses and CIDR network blocks.
     *
     * @param  string  $ip  IP address to check
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
     * Determine if an IP address is present in the permanent Blacklist.
     *
     * @param  string  $ip  IP address to check
     */
    public function isBlacklisted(string $ip): bool
    {
        $blacklist = SecurityIpList::blacklist()->pluck('ip_address')->all();

        if (empty($blacklist)) {
            return false;
        }

        return IpUtils::checkIp($ip, $blacklist);
    }

    /**
     * Check if an active rule explicitly accepts traffic from this IP address.
     *
     * @param  string  $ip  IP address to check
     */
    private function isCoveredByAcceptRule(string $ip): bool
    {
        $acceptRules = SecurityRule::active()
            ->whereIn('action', ['accept', 'allow'])
            ->get();

        foreach ($acceptRules as $rule) {
            $sourceIp = trim($rule->source_ip);

            if ($sourceIp === '' || $sourceIp === 'any' || $sourceIp === '0.0.0.0/0') {
                continue;
            }

            if (IpUtils::checkIp($ip, [$sourceIp])) {
                return true;
            }
        }

        return false;
    }
}
