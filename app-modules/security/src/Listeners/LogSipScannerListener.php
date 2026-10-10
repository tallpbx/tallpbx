<?php

declare(strict_types=1);

namespace Modules\Security\Listeners;

use App\Events\FreeSwitch\FreeSwitchEvent;
use App\Events\FreeSwitch\SipScannerDetected;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Services\SipScannerDialplanContributor;
use Modules\Security\Support\AddressFamily;
use Modules\Security\Support\SipScannerSignatures;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Event listener that turns dialplan scanner detections into bans or records.
 *
 * The dialplan reports what it saw (signature type, value, confidence, and
 * the true socket peer address); this listener makes the enforcement
 * decision:
 *   - High-confidence signatures ban immediately — when the per-feature
 *     enforcement toggle is on — through SecurityBanService (never the
 *     kernel executor directly), so the database row, the audit entry, the
 *     kernel set, and the conntrack flush stay consistent.
 *   - Low-confidence signatures are strictly record-only (never auto-banned),
 *     preventing shared NAT office environments with multiple distinct devices
 *     from being accidentally banned.
 *   - While enforcement is off (the shipped record-only mode), every match
 *     is recorded as a non-enforcing incident row: the Attackers tab surfaces
 *     it under "Detected Attack Probes" with a "Ban IP" action, and nothing is
 *     blocked.
 *
 * Safety nets: addresses that fail address validation are discarded
 * outright (the value arrives from a FreeSWITCH channel variable and is
 * treated as untrusted input), trusted-list addresses are never banned, and
 * a scanner flood cannot amplify database or process work: an existing
 * active ban short-circuits the whole handler, and in record-only mode the
 * audit write is throttled to one entry per address per minute while the
 * incident row still counts every hit.
 *
 * Registered-device protection: a device's contact network address is not
 * reliably available inside the ESL event loop (querying Sofia would interleave
 * on the shared event socket), so the trusted list is the exemption path —
 * the ban service itself refuses whitelisted addresses as well.
 */
class LogSipScannerListener
{
    /**
     * Create the event listener.
     *
     * @param  SecurityBanServiceInterface  $banService  Ban management service (kernel + database consistency)
     */
    public function __construct(
        private readonly SecurityBanServiceInterface $banService,
    ) {}

    /**
     * Handle incoming SIP scanner detection events.
     *
     * Accepts both the typed event and the generic CustomEvent carrying the
     * same Event-Subclass, so the listener works regardless of which class
     * the ESL dispatcher mapped the event to.
     */
    public function handle(FreeSwitchEvent $event): void
    {
        if (! $event instanceof SipScannerDetected
            && $event->header('Event-Subclass') !== SipScannerDialplanContributor::EVENT_SUBCLASS) {
            return;
        }

        // 1. The attacker address arrives from a channel variable: treat it
        //    as untrusted input and discard anything that is not a valid IP.
        $ip = trim((string) ($event->header('Attacker-IP') ?? ''));

        if (! AddressFamily::isValidAddress($ip)) {
            Log::warning('Discarding SIP scanner event with invalid attacker address.', [
                'attacker_ip' => $ip,
            ]);

            return;
        }

        // 2. Trusted addresses are never auto-banned, whatever matched.
        if ($this->isWhitelisted($ip)) {
            return;
        }

        // 3. Idempotency: an active ban already covers this address, so no
        //    second row, audit entry, or privileged operation is produced.
        $alreadyBanned = SecurityBan::active()->where('ip_address', $ip)->exists();

        if ($alreadyBanned) {
            return;
        }

        $type = trim((string) ($event->header('Scanner-Type') ?? 'Unknown'));
        $value = trim((string) ($event->header('Scanner-Value') ?? ''));
        $confidence = $event instanceof SipScannerDetected
            ? $event->confidence()
            : (strtolower((string) ($event->header('Scanner-Confidence') ?? 'high')) === 'low' ? 'low' : 'high');

        // The reason column is 255 characters; a client controls its whole
        // User-Agent, so the captured value is bounded before it is stored.
        $reason = sprintf(
            "Scanner signature match: %s = '%s' (confidence: %s)",
            $type,
            mb_substr($value, 0, 180),
            $confidence,
        );

        // 4. Tier rules: high-confidence signatures ban when enforcement is on.
        //    Low-confidence signatures are strictly record-only to prevent
        //    shared NAT networks with multiple devices from being banned.
        $enforcement = SipScannerSignatures::enforcementEnabled();
        $shouldBan = $enforcement && $confidence === 'high';

        if ($shouldBan) {
            $seconds = SipScannerSignatures::banSeconds();

            $this->banService->ban(
                ip: $ip,
                vector: 'sip_scanner',
                reason: $reason,
                durationSeconds: $seconds === 0 ? null : $seconds,
            );

            return;
        }

        // 5. Record-only path: a non-enforcing incident row plus the audit
        //    entry. Every enforcement consumer reads SecurityBan::active()
        //    only, so this row blocks nothing.
        $this->recordIncident($ip, $reason, $type, $value, $confidence);
    }

    /**
     * Store a detected-but-not-blocked incident.
     *
     * Repeated detections from the same address update the existing
     * incident record (attempt count and latest reason) instead of creating
     * duplicate rows; the first detection time is preserved. The audit entry
     * is throttled to one write per address per minute so a scanner flood
     * during record-only mode cannot grow the audit trail without bound.
     */
    private function recordIncident(string $ip, string $reason, string $type, string $value, string $confidence): void
    {
        $incident = SecurityBan::where('ip_address', $ip)
            ->where('is_active', false)
            ->first();

        if ($incident !== null) {
            $incident->update([
                'vector' => 'sip_scanner',
                'reason' => $reason,
                'attempt_count' => $incident->attempt_count + 1,
            ]);
        } else {
            $incident = SecurityBan::create([
                'ip_address' => $ip,
                'vector' => 'sip_scanner',
                'reason' => $reason,
                'attempt_count' => 1,
                'banned_at' => now(),
                'expires_at' => null,
                'is_active' => false,
            ]);
        }

        // Flood guard: while enforcement is off no ban exists to
        // short-circuit on, so the audit write itself is throttled — one
        // entry per address per minute. A Redis outage degrades to
        // always-audit: visibility beats silence.
        try {
            $throttleKey = "tallpbx:security:sip_scanner_audited:{$ip}";

            if ((bool) Redis::exists($throttleKey)) {
                return;
            }

            Redis::set($throttleKey, '1');
            Redis::expire($throttleKey, 60);
        } catch (\Throwable $e) {
            Log::warning("Failed to apply the SIP scanner audit throttle for {$ip}: {$e->getMessage()}");
        }

        SecurityAuditLog::record(
            action: 'sip_scanner_detected',
            ipAddress: $ip,
            description: "SIP scanner signature detected (not blocked): {$reason}",
            details: [
                'scanner_type' => $type,
                'scanner_value' => mb_substr($value, 0, 180),
                'confidence' => $confidence,
                'enforcement' => SipScannerSignatures::enforcementEnabled() ? 'on' : 'off',
                'attempt_count' => $incident->attempt_count,
            ],
        );
    }

    /**
     * Determine if an address is covered by any trusted list entry.
     *
     * Supports exact IPv4/IPv6 matches and CIDR subnets, mirroring the
     * incident service's whitelist semantics.
     */
    private function isWhitelisted(string $ip): bool
    {
        $whitelist = SecurityIpList::whitelist()->pluck('ip_address')->all();

        if (empty($whitelist)) {
            return false;
        }

        return IpUtils::checkIp($ip, $whitelist);
    }
}
