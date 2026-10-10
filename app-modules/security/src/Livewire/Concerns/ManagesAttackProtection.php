<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Rules\ValidFirewallAddress;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Support\SipScannerSignatures;

/**
 * Livewire component concern for brute force protection, SIP scanners, and bans.
 */
trait ManagesAttackProtection
{
    public int $maxRetry = 5;

    public int $findTime = 600;

    public int $banTime = 3600;

    public bool $protectSip = true;

    public bool $protectWeb = true;

    public bool $protectSsh = true;

    public bool $attackProtectionEnabled = true;

    public bool $tftpDefenseEnabled = true;

    public bool $showSettingsDrawer = false;

    public bool $showManualBanModal = false;

    public string $manualBanIp = '';

    public int $manualBanDuration = 86400;

    public string $manualBanReason = '';

    public bool $sipScannerEnforcement = true;

    public int $sipScannerBanSeconds = 86400;

    public string $newScannerSignature = '';

    /**
     * Lift an active ban on an attacker IP address.
     */
    public function unban(string $ip, SecurityBanServiceInterface $banService): void
    {
        $adminId = Auth::guard('admin')->id();
        $banService->unban($ip, is_int($adminId) ? $adminId : null);

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_unbanned_success'));
        }
    }

    /**
     * Open the modal to apply a manual ban.
     */
    public function openManualBanModal(): void
    {
        $this->manualBanIp = '';
        $this->manualBanDuration = 86400;
        $this->manualBanReason = '';
        $this->showManualBanModal = true;
    }

    /**
     * Execute a manual ban against a specific IP address.
     */
    public function manualBan(SecurityBanServiceInterface $banService, ?LockoutGuardService $lockoutGuard = null): void
    {
        $lockoutGuard ??= app(LockoutGuardService::class);

        $this->validate([
            'manualBanIp' => [
                'required',
                new ValidFirewallAddress((string) __('admin.security_ban_ip_format_invalid'), allowCidr: false),
            ],
            'manualBanDuration' => ['required', 'integer'],
            'manualBanReason' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->manualBanIp);
        $reason = $this->manualBanReason ? trim($this->manualBanReason) : 'Manual administrative ban';

        if ($lockoutGuard->isWhitelisted($ip)) {
            $this->showManualBanModal = false;
            $this->notifyError((string) __('admin.security_cannot_ban_whitelisted', ['ip' => $ip]));

            return;
        }

        $permanentBlacklistEntry = $this->manualBanDuration === -1;

        if ($this->manualBanDuration === -1) {
            SecurityIpList::updateOrCreate(
                ['type' => 'blacklist', 'ip_address' => $ip],
                ['description' => $reason]
            );
        } else {
            try {
                $banService->ban($ip, 'manual', $reason, $this->manualBanDuration);
            } catch (\InvalidArgumentException) {
                $this->showManualBanModal = false;
                $this->notifyError((string) __('admin.security_cannot_ban_whitelisted', ['ip' => $ip]));

                return;
            }
        }

        $this->showManualBanModal = false;

        if ($this->autoApplyFirewallRuleset()) {
            if ($permanentBlacklistEntry) {
                $banService->flushConntrack($ip);
            }

            $this->notifySuccess((string) __('admin.security_manual_ban_success'));
        }
    }

    /**
     * Turn the automatic scanner-ban enforcement on or off.
     */
    public function setSipScannerEnforcement(bool $enabled): void
    {
        SecuritySetting::set(SipScannerSignatures::ENFORCEMENT_KEY, $enabled);
        $this->sipScannerEnforcement = $enabled;

        SecurityAuditLog::record(
            action: $enabled ? 'sip_scanner_enforcement_enabled' : 'sip_scanner_enforcement_disabled',
            ipAddress: $this->adminIp,
            description: $enabled
                ? 'Automatic SIP scanner bans enabled: high-confidence matches now ban immediately'
                : 'Automatic SIP scanner bans disabled: matches are recorded and listed without blocking',
            adminId: Auth::guard('admin')->id(),
        );

        $this->notifySuccess((string) __('admin.security_scanner_saved'));
    }

    /**
     * Persist the scanner ban duration from the selector.
     */
    public function setSipScannerBanSeconds(mixed $seconds): void
    {
        $candidate = (int) $seconds;

        if (! in_array($candidate, [0, 3600, 86400, 604800], true)) {
            $this->notifyError((string) __('admin.security_scanner_duration_invalid'));

            return;
        }

        SecuritySetting::set(SipScannerSignatures::BAN_SECONDS_KEY, $candidate);
        $this->sipScannerBanSeconds = $candidate;

        SecurityAuditLog::record(
            action: 'sip_scanner_ban_duration_updated',
            ipAddress: $this->adminIp,
            description: $candidate === 0
                ? 'SIP scanner ban duration set to permanent'
                : "SIP scanner ban duration set to {$candidate} seconds",
            adminId: Auth::guard('admin')->id(),
        );

        $this->notifySuccess((string) __('admin.security_scanner_saved'));
    }

    /**
     * Add a custom scanner signature.
     */
    public function addScannerSignature(): void
    {
        $validated = SipScannerSignatures::validateCustomEntry($this->newScannerSignature);

        if ($validated === null) {
            $this->addError('newScannerSignature', (string) __('admin.security_scanner_signature_invalid'));

            return;
        }

        $lower = mb_strtolower($validated);
        $stored = array_map(static fn (string $entry): string => mb_strtolower($entry), SipScannerSignatures::custom());

        if (in_array($lower, $stored, true) || in_array($lower, SipScannerSignatures::basePatternsLowercased(), true)) {
            $this->addError('newScannerSignature', (string) __('admin.security_scanner_signature_duplicate'));

            return;
        }

        SipScannerSignatures::saveCustom(array_merge(SipScannerSignatures::custom(), [$validated]));

        SecurityAuditLog::record(
            action: 'sip_scanner_signature_added',
            ipAddress: $this->adminIp,
            description: "Custom SIP scanner signature added: {$validated}",
            adminId: Auth::guard('admin')->id(),
        );

        $this->newScannerSignature = '';
        $this->notifySuccess((string) __('admin.security_scanner_signature_added'));
    }

    /**
     * Remove one stored custom scanner signature.
     */
    public function removeScannerSignature(string $signature): void
    {
        $custom = SipScannerSignatures::custom();
        $lower = mb_strtolower(trim($signature));

        $remaining = array_values(array_filter(
            $custom,
            static fn (string $entry): bool => mb_strtolower($entry) !== $lower,
        ));

        if (count($remaining) === count($custom)) {
            $this->notifyError((string) __('admin.security_scanner_incident_missing'));

            return;
        }

        SipScannerSignatures::saveCustom($remaining);

        SecurityAuditLog::record(
            action: 'sip_scanner_signature_removed',
            ipAddress: $this->adminIp,
            description: "Custom SIP scanner signature removed: {$signature}",
            adminId: Auth::guard('admin')->id(),
        );

        $this->notifySuccess((string) __('admin.security_scanner_signature_removed'));
    }

    /**
     * Add a detected-but-unblocked address to the auto-ban list.
     */
    public function promoteScannerIncident(string $ip, SecurityBanServiceInterface $banService): void
    {
        $incident = SecurityBan::where('ip_address', $ip)
            ->where('vector', 'sip_scanner')
            ->where('is_active', false)
            ->first();

        if ($incident === null) {
            $this->notifyError((string) __('admin.security_scanner_incident_missing'));

            return;
        }

        $seconds = SipScannerSignatures::banSeconds();

        try {
            $banService->ban(
                ip: $ip,
                vector: 'sip_scanner',
                reason: (string) $incident->reason,
                durationSeconds: $seconds === 0 ? null : $seconds,
            );
        } catch (\InvalidArgumentException) {
            $this->notifyError((string) __('admin.security_scanner_incident_refused'));

            return;
        }

        $this->notifySuccess((string) __('admin.security_scanner_incident_banned'));
    }

    /**
     * Turn the hardened TFTP defense profile on or off.
     */
    public function setTftpDefense(bool $enabled, LockoutGuardService $lockoutGuard): void
    {
        SecuritySetting::updateOrCreate(['key' => 'tftp_defense_enabled'], ['value' => $enabled ? '1' : '0']);
        $this->tftpDefenseEnabled = $enabled;

        SecurityAuditLog::record(
            action: $enabled ? 'tftp_defense_enabled' : 'tftp_defense_disabled',
            ipAddress: $this->adminIp,
            description: $enabled
                ? 'Hardened TFTP Defense Profile enabled: write uploads, traversal probes, and floods are blocked before the port catalog'
                : 'Hardened TFTP Defense Profile disabled: TFTP provisioning is accepted without the defensive rules',
            adminId: Auth::guard('admin')->id(),
        );

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_tftp_defense_saved'));
        }
    }

    /**
     * Read the per-rule TFTP defense counters from the live kernel ruleset.
     *
     * @return array{uploads: int|null, traversal: int|null, probes: int|null, flood: int|null}
     */
    public function tftpDefenseCounters(): array
    {
        $status = app(SecurityExecutorInterface::class)->status();

        $counters = [
            'uploads' => null,
            'traversal' => null,
            'probes' => null,
            'flood' => null,
        ];

        if ($status === '') {
            return $counters;
        }

        $extract = static function (string $pattern) use ($status): ?int {
            return preg_match($pattern, $status, $matches) === 1 ? (int) $matches[1] : null;
        };

        $counters['uploads'] = $extract('/@th,64,16 0x0*2 counter packets (\d+)/');
        $counters['traversal'] = $extract('/@th,80,24 0x2e2e2f counter packets (\d+)/');
        $counters['probes'] = $extract('/(?:@th,80,16 0x2f78|@th,64,32 0x12f78) counter packets (\d+)/');

        $floodV4 = $extract('/@tftp_flood4 .* counter packets (\d+)/');
        $floodV6 = $extract('/@tftp_flood6 .* counter packets (\d+)/');
        $counters['flood'] = ($floodV4 === null && $floodV6 === null)
            ? null
            : (int) (($floodV4 ?? 0) + ($floodV6 ?? 0));

        return $counters;
    }

    /**
     * Open the attack protection settings drawer.
     */
    public function openSettingsDrawer(): void
    {
        $this->loadSettings();
        $this->loadFeedState();
        $this->showSettingsDrawer = true;
    }

    /**
     * Close the attack protection settings drawer.
     */
    public function closeSettingsDrawer(): void
    {
        $this->showSettingsDrawer = false;
    }

    /**
     * Save attack protection sensitivity settings and toggles.
     */
    public function saveSettings(): void
    {
        $this->validate([
            'maxRetry' => ['required', 'integer', 'min:1', 'max:100'],
            'findTime' => ['required', 'integer', 'min:10', 'max:86400'],
            'banTime' => ['required', 'integer', 'min:60', 'max:31536000'],
        ]);

        SecuritySetting::updateOrCreate(['key' => 'max_retry'], ['value' => (string) $this->maxRetry]);
        SecuritySetting::updateOrCreate(['key' => 'find_time'], ['value' => (string) $this->findTime]);
        SecuritySetting::updateOrCreate(['key' => 'ban_time'], ['value' => (string) $this->banTime]);
        SecuritySetting::updateOrCreate(['key' => 'protect_sip'], ['value' => $this->protectSip ? '1' : '0']);
        SecuritySetting::updateOrCreate(['key' => 'protect_web'], ['value' => $this->protectWeb ? '1' : '0']);
        SecuritySetting::updateOrCreate(['key' => 'protect_ssh'], ['value' => $this->protectSsh ? '1' : '0']);
        SecuritySetting::updateOrCreate(['key' => 'firewall_enabled'], ['value' => $this->firewallEnabled ? '1' : '0']);
        SecuritySetting::updateOrCreate(['key' => 'attack_protection_enabled'], ['value' => $this->attackProtectionEnabled ? '1' : '0']);

        $this->showSettingsDrawer = false;

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_settings_saved'));
        }
    }
}
