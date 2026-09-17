<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Service that tracks failed authentication incidents and enforces rate limits.
 *
 * Uses Redis sliding-window counters to track failed attempts within find_time.
 * Automatically delegates to SecurityBanServiceInterface when failure thresholds are exceeded.
 * Whitelisted IP addresses are unconditionally exempted from tracking and bans.
 */
class SecurityIncidentService implements SecurityIncidentServiceInterface
{
    /**
     * Create the incident service instance.
     *
     * @param  SecurityBanServiceInterface|null  $banService  Optional ban management service
     */
    public function __construct(
        private readonly ?SecurityBanServiceInterface $banService = null,
    ) {}

    /**
     * Record an authentication or access failure.
     *
     * Increments the failure counter in Redis, sets expiration on first failure,
     * and triggers a ban if the threshold is exceeded.
     *
     * @param  string  $ip  Source IP address of the failed request
     * @param  string  $vector  Attack vector: 'web_auth', 'sip_auth', or 'ssh'
     * @param  string  $details  Contextual details such as attempted username
     */
    public function recordFailure(string $ip, string $vector, string $details = ''): void
    {
        // 1. Whitelisted IPs are completely exempt from tracking and banning
        if ($this->isWhitelisted($ip)) {
            return;
        }

        // 2. Check if intrusion detection is active globally and for this specific vector
        if (! $this->isVectorProtected($vector)) {
            return;
        }

        $findTime = SecuritySetting::getInt('find_time', 600);
        $maxRetry = SecuritySetting::getInt('max_retry', 5);
        $banTime = SecuritySetting::getInt('ban_time', 3600);

        try {
            $redisKey = "tallpbx:security:attempts:{$vector}:{$ip}";
            $attempts = (int) Redis::incr($redisKey);

            if ($attempts === 1) {
                Redis::expire($redisKey, $findTime);
            }

            if ($attempts >= $maxRetry) {
                Redis::del($redisKey);

                if ($this->banService !== null) {
                    $this->banService->ban(
                        ip: $ip,
                        vector: $vector,
                        reason: "Exceeded {$maxRetry} failed attempts within {$findTime}s: {$details}",
                        durationSeconds: $banTime > 0 ? $banTime : null,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error('Security incident tracking error in Redis', [
                'ip' => $ip,
                'vector' => $vector,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine if an IP address is covered by any trusted whitelist entry.
     *
     * Supports exact IPv4/IPv6 matches as well as CIDR notation subnets.
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
     * Check if intrusion detection is active globally and for the given vector.
     *
     * @param  string  $vector  Attack vector identifier
     */
    public function isVectorProtected(string $vector): bool
    {
        if (! SecuritySetting::getBoolean('intrusion_detection_enabled', true)) {
            return false;
        }

        return match ($vector) {
            'web_auth' => SecuritySetting::getBoolean('protect_web', true),
            'sip_auth' => SecuritySetting::getBoolean('protect_sip', true),
            'ssh' => SecuritySetting::getBoolean('protect_ssh', true),
            default => true,
        };
    }
}
