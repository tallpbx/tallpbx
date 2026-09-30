<?php

declare(strict_types=1);

namespace Modules\Security\Livewire;

use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Events\FirewallRulesetUpdated;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Rules\ValidFirewallAddress;
use Modules\Security\Services\FirewallSyncVerifier;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Services\SecurityConfigGenerator;
use Modules\Security\Services\ThreatFeedIngestionService;
use Modules\Security\Services\ThreatFeedManager;
use Modules\Security\Support\SipScannerSignatures;
use Symfony\Component\HttpFoundation\IpUtils;

#[Layout('layouts.app')]

/**
 * Security Command Center Livewire component.
 *
 * Provides a unified, single-screen management interface for host firewall rules,
 * trusted and blocked IP address lists, live attack detection, and intrusion thresholds.
 */
class SecurityManager extends Component
{
    use HasOperationalFeedback;

    /**
     * Active tab in the evaluation-ordered tab strip.
     *
     * The tabs mirror the kernel evaluation order: the allow/block lists,
     * the attackers, the threat feeds, and the full firewall pipeline.
     * Exposed as ?tab= so deep links like /panel/security?tab=threat-feeds
     * open the matching panel.
     */
    #[Url(as: 'tab')]
    public string $activeTab = 'block-allow';

    /**
     * Search query for filtering IP entries.
     */
    public string $ipSearch = '';

    /**
     * New IP or CIDR to add to the blacklist.
     */
    public string $newBlacklistIp = '';

    /**
     * Optional label or note for the new blacklist IP entry.
     */
    public string $newBlacklistDescription = '';

    /**
     * Search query for filtering blacklist IP entries.
     */
    public string $blacklistSearch = '';

    /**
     * New IP or CIDR to add to the whitelist.
     */
    public string $newWhitelistIp = '';

    /**
     * Optional label or note for the new whitelist IP entry.
     */
    public string $newWhitelistDescription = '';

    /**
     * Search query for filtering whitelist IP entries.
     */
    public string $whitelistSearch = '';

    /**
     * The IP address of the currently connected administrator.
     */
    public string $adminIp = '';

    /**
     * Whether the administrator's current IP is protected in the trusted list.
     */
    public bool $isCurrentIpWhitelisted = false;

    /**
     * Count of unapplied firewall changes waiting to be applied to the kernel.
     */
    public int $pendingChangesCount = 0;

    /**
     * Visibility state for the manual IP ban modal.
     */
    public bool $showManualBanModal = false;

    /**
     * Target IP address for manual ban.
     */
    public string $manualBanIp = '';

    /**
     * Ban duration in seconds (defaults to 86400 / 24 hours).
     */
    public int $manualBanDuration = 86400;

    /**
     * Reason text for manual ban.
     */
    public string $manualBanReason = '';

    /**
     * Visibility state for the custom firewall rule modal.
     */
    public bool $showRuleModal = false;

    /**
     * ID of the rule being edited, or null if creating a new rule.
     */
    public ?int $editingRuleId = null;

    /**
     * Form inputs for firewall rule.
     */
    public string $ruleDescription = '';

    public string $ruleSourceIp = 'any';

    public ?int $ruleServiceId = null;

    public string $ruleCustomPort = '';

    public string $ruleCustomProtocol = 'tcp';

    public string $ruleAction = 'accept';

    public bool $ruleEnabled = true;

    /**
     * Visibility state for the system service configuration modal.
     */
    public bool $showSystemServiceModal = false;

    /**
     * ID of the system service being edited.
     */
    public ?int $editingSystemServiceId = null;

    /**
     * Form inputs for editing a core PBX system service.
     */
    public string $systemServiceName = '';

    public string $systemServiceDescription = '';

    public string $systemServicePortRange = '';

    public string $systemServiceProtocol = 'tcp';

    public string $systemServiceSourceIp = 'any';

    public bool $systemServiceEnabled = true;

    public bool $systemServiceRateLimitEnabled = true;

    public ?int $systemServiceRateLimit = 5;

    public ?int $systemServiceBurst = 5;

    /**
     * Visibility state for the attack protection settings slide-over drawer.
     */
    public bool $showSettingsDrawer = false;

    /**
     * Visibility state for the dedicated Default Inbound Policy form.
     */
    public bool $showDefaultPolicyModal = false;

    /**
     * Intrusion protection sensitivity thresholds.
     */
    public int $maxRetry = 5;

    public int $findTime = 600;

    public int $banTime = 86400;

    public bool $protectSip = true;

    public bool $protectWeb = true;

    public bool $protectSsh = true;

    public string $firewallDefaultPolicy = 'drop';

    /**
     * Observed default inbound policy reported by the live Linux kernel:
     * 'drop' or 'accept' when readable, 'absent' when the TallPBX firewall
     * table is not loaded, or NULL when the kernel state cannot be read.
     */
    public ?string $liveFirewallPolicy = null;

    /**
     * Cryptographic and kernel firewall synchronization state:
     * 'verified', 'drift', or 'unknown'.
     */
    public ?string $firewallSyncState = null;

    /**
     * Timestamp when the active firewall ruleset was applied to the host, if recorded.
     */
    public ?string $firewallSyncAppliedAt = null;

    /**
     * Primary reason or first detected issue if the firewall is out of sync or unknown.
     */
    public ?string $firewallSyncReason = null;

    public bool $firewallEnabled = true;

    /**
     * Whether the built-in pre-filter pipeline (stages 1–7) is running.
     *
     * When off, every pre-filter stage is skipped while the rest of the
     * firewall chain keeps running; the administrator may re-author any of
     * the removed rules in the custom rules section.
     */
    public bool $prefilterEnabled = true;

    /**
     * Whether the global observe mode is running.
     *
     * While observing, every drop rule evaluates, counts, and logs but
     * nothing is blocked, and the default policy is forced to accept.
     */
    public bool $firewallObserveMode = false;

    /**
     * Whether the hardened TFTP defense profile is active.
     */
    public bool $tftpDefenseEnabled = true;

    /**
     * Whether scanner detections trigger an automatic kernel ban
     * (per-feature enforcement; ships off for the record-only rollout).
     */
    public bool $sipScannerEnforcement = false;

    /**
     * Ban duration in seconds for scanner bans (0 = permanent).
     */
    public int $sipScannerBanSeconds = 86400;

    /**
     * Input for a new custom scanner signature.
     */
    public string $newScannerSignature = '';

    /**
     * Whether the public threat feed blocks traffic in the kernel.
     */
    public bool $feedEnabled = false;

    /**
     * Country filtering mode for the feed ('all', 'blacklist', 'whitelist').
     */
    public string $feedCountryMode = 'all';

    /**
     * Comma-separated country codes input for the feed's country filtering.
     */
    public string $feedCountriesInput = '';

    /**
     * How often the feed refreshes ('hourly', '4_hours', '12_hours', 'daily').
     */
    public string $feedSyncInterval = 'daily';

    public bool $attackProtectionEnabled = true;

    /**
     * Mount the component and load initial state from database.
     */
    public function mount(LockoutGuardService $lockoutGuard): void
    {
        $this->adminIp = request()->ip() ?? '127.0.0.1';
        $this->checkAdminIpStatus($lockoutGuard);
        $this->loadSettings();
        $this->loadFeedState();
        $this->refreshLiveFirewallPolicy();
        $this->refreshFirewallSyncState();
    }

    /**
     * Check if the administrator's current connection is safe from lockout.
     */
    public function checkAdminIpStatus(LockoutGuardService $lockoutGuard): void
    {
        $this->isCurrentIpWhitelisted = $lockoutGuard->isWhitelisted($this->adminIp);
    }

    /**
     * Refresh the observed kernel firewall policy so the UI can flag drift
     * between the saved configuration and what nftables is actually running.
     * Unreadable state leaves the indicator silent instead of warning falsely.
     */
    private function refreshLiveFirewallPolicy(): void
    {
        try {
            $output = app(SecurityExecutorInterface::class)->status();
        } catch (\Throwable) {
            // The status helper is unavailable on this host (for example a
            // development machine): keep the observed state unknown.
            $this->liveFirewallPolicy = null;

            return;
        }

        if (trim($output) === '') {
            $this->liveFirewallPolicy = null;

            return;
        }

        // The helper falls back to the full ruleset listing when the TallPBX
        // table is missing entirely, which means the firewall is not loaded.
        if (! str_contains($output, 'table inet tallpbx_filter')) {
            $this->liveFirewallPolicy = 'absent';

            return;
        }

        // Extract the live input-hook policy (drop or accept) from the kernel's nft status output.
        $this->liveFirewallPolicy = preg_match(
            '/type\s+filter\s+hook\s+input[^;]*;\s*policy\s+(drop|accept)\s*;/',
            $output,
            $matches
        ) === 1 ? $matches[1] : null;
    }

    /**
     * Refresh the cryptographic and kernel synchronization state of the firewall.
     */
    private function refreshFirewallSyncState(): void
    {
        try {
            $status = app(FirewallSyncVerifier::class)->verify();
            $this->firewallSyncState = $status->state;
            $this->firewallSyncAppliedAt = $status->appliedAt;
            $this->firewallSyncReason = $status->issues[0] ?? null;
        } catch (\Throwable) {
            $this->firewallSyncState = 'unknown';
            $this->firewallSyncAppliedAt = null;
            $this->firewallSyncReason = null;
        }
    }

    /**
     * Load settings from the database into public properties.
     */
    public function loadSettings(): void
    {
        $this->maxRetry = (int) SecuritySetting::get('max_retry', '5');
        $this->findTime = (int) SecuritySetting::get('find_time', '600');
        $this->banTime = (int) SecuritySetting::get('ban_time', '86400');
        $this->protectSip = SecuritySetting::getBoolean('protect_sip', true);
        $this->protectWeb = SecuritySetting::getBoolean('protect_web', true);
        $this->protectSsh = SecuritySetting::getBoolean('protect_ssh', true);
        $this->firewallDefaultPolicy = SecuritySetting::get('firewall_default_policy', 'drop') ?? 'drop';
        $this->firewallEnabled = SecuritySetting::getBoolean('firewall_enabled', true);
        $this->prefilterEnabled = SecuritySetting::getBoolean('prefilter_enabled', true);
        $this->firewallObserveMode = SecuritySetting::getBoolean('firewall_observe_mode', false);
        $this->tftpDefenseEnabled = SecuritySetting::getBoolean('tftp_defense_enabled', true);
        // Scanner enforcement and duration resolve through the signature
        // registry so the panel and the listener can never disagree.
        $this->sipScannerEnforcement = SipScannerSignatures::enforcementEnabled();
        $this->sipScannerBanSeconds = SipScannerSignatures::banSeconds();
        $this->attackProtectionEnabled = SecuritySetting::getBoolean('attack_protection_enabled', true);
        $this->pendingChangesCount = (int) SecuritySetting::get('pending_changes_count', '0');
    }

    /**
     * 1-click rescue action: unconditionally whitelist the current administrator IP.
     */
    public function whitelistCurrentIp(LockoutGuardService $lockoutGuard): void
    {
        $lockoutGuard->whitelistIp($this->adminIp, 'Auto-whitelisted administrator session');
        $this->isCurrentIpWhitelisted = true;

        // Report success only when the kernel actually accepted the ruleset.
        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_ip_protected_success'));
        }
    }

    /**
     * Turn the built-in pre-filter pipeline (stages 1–6 plus the feed drops)
     * on or off.
     *
     * Turning it off is the one switch that removes the whitelist accept rule
     * itself, so the administrator's own connection is verified first: if
     * their address would be dropped by the remaining ruleset, the change is
     * refused outright — with an alert explaining exactly why — and nothing
     * is persisted or applied. The fix is to add the address to the Trusted
     * List and try again.
     */
    public function setPrefilterEnabled(bool $enabled, LockoutGuardService $lockoutGuard): void
    {
        // Enabling is purely protective: it can never lock anyone out.
        if (! $enabled && ! $lockoutGuard->isIpSafe($this->adminIp !== '' ? $this->adminIp : null, $this->firewallDefaultPolicy)) {
            $this->notifyError((string) __('admin.security_prefilter_lockout_refused', ['ip' => $this->adminIp]));

            return;
        }

        // Removing the pre-filter also removes the unconditional loopback
        // accept that the server's own database, cache, and phone services
        // rely on; combined with a blocking default policy that would sever
        // them, so the change is refused before anything is persisted.
        if (! $enabled && $lockoutGuard->wouldDropLocalServices(proposedPrefilterEnabled: false)) {
            $this->notifyError((string) __('admin.security_prefilter_local_services_refused'));

            return;
        }

        SecuritySetting::updateOrCreate(['key' => 'prefilter_enabled'], ['value' => $enabled ? '1' : '0']);
        $this->prefilterEnabled = $enabled;

        SecurityAuditLog::record(
            action: $enabled ? 'prefilter_enabled' : 'prefilter_disabled',
            ipAddress: $this->adminIp,
            description: $enabled
                ? 'Built-in pre-filter pipeline re-enabled from the Security Center'
                : 'Built-in pre-filter pipeline disabled from the Security Center; stages 1–7 are removed from the pending ruleset',
            adminId: Auth::guard('admin')->id(),
        );

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_settings_saved'));
        }
    }

    /**
     * Turn the global observe mode on or off.
     *
     * Observe mode itself is permissive, so turning it on needs no guard.
     * Turning it OFF restores enforcement — the dangerous direction — so the
     * administrator's address is verified against the restored policy first
     * and the change is refused with an explanatory alert when it would
     * sever their own connection.
     */
    public function setObserveMode(bool $enabled, LockoutGuardService $lockoutGuard): void
    {
        if (! $enabled && ! $lockoutGuard->isIpSafe($this->adminIp !== '' ? $this->adminIp : null, $this->firewallDefaultPolicy)) {
            $this->notifyError((string) __('admin.security_observe_restore_lockout_refused', ['ip' => $this->adminIp]));

            return;
        }

        // Leaving observe mode restores enforcement; with the pre-filter off
        // and a blocking default policy, the server's own loopback services
        // would hit the default drop, so the change is refused up front.
        if (! $enabled && $lockoutGuard->wouldDropLocalServices(proposedObserveMode: false)) {
            $this->notifyError((string) __('admin.security_observe_local_services_refused'));

            return;
        }

        SecuritySetting::updateOrCreate(['key' => 'firewall_observe_mode'], ['value' => $enabled ? '1' : '0']);
        $this->firewallObserveMode = $enabled;

        SecurityAuditLog::record(
            action: $enabled ? 'observe_mode_enabled' : 'observe_mode_disabled',
            ipAddress: $this->adminIp,
            description: $enabled
                ? 'Global observe mode enabled: the firewall now evaluates and logs every match without blocking'
                : 'Global observe mode disabled: enforcement restored',
            adminId: Auth::guard('admin')->id(),
        );

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_settings_saved'));
        }
    }

    /**
     * Turn the hardened TFTP defense profile on or off.
     *
     * Neither direction can lock the administrator out (TFTP provisioning
     * is not the management path), so no lockout guard is needed: turning
     * the profile off simply removes the defensive rules while the port
     * catalog keeps TFTP reachable, and turning it on re-arms them.
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
     * Returns one entry per documented counter category — uploads, traversal
     * attempts, scan probes, and flood drops (both address families summed).
     * A category reads as null when the helper cannot report the ruleset or
     * the rule is absent, so the panel can render a dash instead of
     * pretending the count is zero.
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

        // Match the live listed form of each rule (the kernel normalizes
        // `counter drop` to `counter packets N bytes M drop`).
        $extract = static function (string $pattern) use ($status): ?int {
            return preg_match($pattern, $status, $matches) === 1 ? (int) $matches[1] : null;
        };

        $counters['uploads'] = $extract('/@th,64,16 0x0002 counter packets (\d+)/');
        $counters['traversal'] = $extract('/@th,80,24 0x2e2e2f counter packets (\d+)/');
        $counters['probes'] = $extract('/@th,80,16 0x2f78 counter packets (\d+)/');

        $floodV4 = $extract('/@tftp_flood4 .* counter packets (\d+)/');
        $floodV6 = $extract('/@tftp_flood6 .* counter packets (\d+)/');
        $counters['flood'] = ($floodV4 === null && $floodV6 === null)
            ? null
            : (int) (($floodV4 ?? 0) + ($floodV6 ?? 0));

        return $counters;
    }

    /**
     * Turn the whole firewall on or off.
     *
     * Turning it off makes the generated ruleset fully open (policy accept);
     * turning it back on runs through the same lockout guard every apply
     * uses, so the administrator can never enable a ruleset that would drop
     * their own connection.
     */
    public function setFirewallEnabled(bool $enabled, LockoutGuardService $lockoutGuard): void
    {
        // Remember the persisted switch so a refused apply can be rolled back
        // (in that case the kernel is still running the previous ruleset).
        $previous = SecuritySetting::getBoolean('firewall_enabled', true);

        SecuritySetting::updateOrCreate(['key' => 'firewall_enabled'], ['value' => $enabled ? '1' : '0']);
        $this->firewallEnabled = $enabled;

        SecurityAuditLog::record(
            action: $enabled ? 'firewall_enabled' : 'firewall_disabled',
            ipAddress: $this->adminIp,
            description: $enabled
                ? 'Host firewall re-enabled from the Security Center'
                : 'Host firewall disabled from the Security Center; the generated ruleset now accepts all inbound traffic',
            adminId: Auth::guard('admin')->id(),
        );

        if (! $this->autoApplyFirewallRuleset($lockoutGuard)) {
            // The guard, the syntax preflight, or the helper refused the
            // change: snap the stored switch back so the panel never reports
            // a state the kernel is not actually running.
            SecuritySetting::updateOrCreate(['key' => 'firewall_enabled'], ['value' => $previous ? '1' : '0']);
            $this->firewallEnabled = $previous;

            return;
        }

        $this->notifySuccess((string) __('admin.security_settings_saved'));
    }

    /**
     * Build the ordered pre-filter row descriptors for the Firewall Rules tab.
     *
     * Rows follow the stored pre-filter order so the table mirrors what the
     * kernel will actually evaluate; loopback is pinned first (invariant 1's
     * floor) and each stage carries its own label, kernel badge, tooltip,
     * source summary, action, and manage link. Stage keys without a
     * descriptor yet (the threat feed row) are skipped until their feature
     * ships its row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preFilterRows(): array
    {
        $whitelistCount = SecurityIpList::whitelist()->count();
        $blacklistCount = SecurityIpList::blacklist()->count();
        $bannedCount = SecurityBan::active()->count();
        $threatFeedCount = (int) (SecurityThreatFeed::where('provider', 'voipbl')->value('entries_count') ?? 0);

        $descriptors = [
            'loopback' => [
                'label' => __('admin.security_rule_loopback'),
                'badge' => 'iif "lo"',
                'tooltip' => __('admin.security_loopback_tooltip'),
                'invariant' => true,
                'status_class' => 'bg-success',
                'action' => 'allow',
                'source_kind' => 'static',
                'source_static' => '127.0.0.1/8, ::1',
                'count' => null,
                'count_choice' => null,
                'count_class' => '',
                'count_pulse' => false,
                'manage' => null,
                'pinned' => true,
            ],
            'whitelist' => [
                'label' => __('admin.security_trusted_whitelist'),
                'badge' => '@whitelist_ips',
                'tooltip' => null,
                'invariant' => false,
                'status_class' => 'bg-success',
                'action' => 'allow',
                'source_kind' => 'count',
                'source_static' => null,
                'count' => $whitelistCount,
                'count_choice' => 'admin.security_entries_count',
                'count_class' => $whitelistCount > 0 ? 'text-success font-semibold' : 'text-base-content/60',
                'count_pulse' => false,
                'manage' => ['label' => __('admin.security_manage_whitelist'), 'tab' => 'block-allow', 'class' => 'text-success'],
                'pinned' => false,
            ],
            'invalid' => [
                'label' => __('admin.security_rule_invalid_packets'),
                'badge' => 'ct state invalid',
                'tooltip' => __('admin.security_invalid_tooltip'),
                'invariant' => true,
                'status_class' => 'bg-error',
                'action' => 'drop',
                'source_kind' => 'anywhere',
                'source_static' => null,
                'count' => null,
                'count_choice' => null,
                'count_class' => '',
                'count_pulse' => false,
                'manage' => null,
                'pinned' => false,
            ],
            'fast_path' => [
                'label' => __('admin.security_rule_conntrack'),
                'badge' => 'ct state established,related',
                'tooltip' => __('admin.security_conntrack_tooltip'),
                'invariant' => true,
                'status_class' => 'bg-success',
                'action' => 'allow',
                'source_kind' => 'anywhere',
                'source_static' => null,
                'count' => null,
                'count_choice' => null,
                'count_class' => '',
                'count_pulse' => false,
                'manage' => null,
                'pinned' => false,
            ],
            'blacklist' => [
                'label' => __('admin.security_permanent_blacklist'),
                'badge' => '@blacklist_ips',
                'tooltip' => null,
                'invariant' => false,
                'status_class' => 'bg-error',
                'action' => 'drop',
                'source_kind' => 'count',
                'source_static' => null,
                'count' => $blacklistCount,
                'count_choice' => 'admin.security_entries_count',
                'count_class' => $blacklistCount > 0 ? 'text-error font-semibold' : 'text-base-content/60',
                'count_pulse' => false,
                'manage' => ['label' => __('admin.security_manage_blacklist'), 'tab' => 'block-allow', 'class' => 'text-error'],
                'pinned' => false,
            ],
            'banned' => [
                'label' => __('admin.security_active_attackers'),
                'badge' => '@banned_ips',
                'tooltip' => null,
                'invariant' => false,
                'status_class' => 'bg-error',
                'action' => 'drop',
                'source_kind' => 'count',
                'source_static' => null,
                'count' => $bannedCount,
                'count_choice' => 'admin.security_threats_count',
                'count_class' => $bannedCount > 0 ? 'text-error font-semibold' : 'text-base-content/60',
                'count_pulse' => $bannedCount > 0,
                'manage' => ['label' => __('admin.security_view_threats'), 'tab' => 'attackers', 'class' => 'text-error'],
                'pinned' => false,
            ],
            'threat_feeds' => [
                'label' => __('admin.security_threat_feeds_title'),
                'badge' => '@threat_feed_ips',
                'tooltip' => null,
                'invariant' => false,
                'status_class' => 'bg-error',
                'action' => 'drop',
                'source_kind' => 'count',
                'source_static' => null,
                'count' => $threatFeedCount,
                'count_choice' => 'admin.security_entries_count',
                'count_class' => $threatFeedCount > 0 ? 'text-error font-semibold' : 'text-base-content/60',
                'count_pulse' => false,
                'manage' => ['label' => __('admin.security_threat_feed_manage'), 'tab' => 'threat-feeds', 'class' => 'text-error'],
                'pinned' => false,
            ],
        ];

        $rows = [];
        foreach (app(SecurityConfigGenerator::class)->preFilterOrder() as $stageKey) {
            // A stage key without a descriptor yet is skipped so a future
            // stage can ship its row in a later release without breaking
            // the table here.
            if (isset($descriptors[$stageKey])) {
                $rows[] = ['key' => $stageKey] + $descriptors[$stageKey];
            }
        }

        return $rows;
    }

    /**
     * Load the threat feed configuration into the form properties.
     *
     * A feed row that has never been saved yet shows the defaults; the row
     * itself is only created on the first write, so merely viewing the tab
     * never touches the database.
     */
    public function loadFeedState(): void
    {
        $feed = SecurityThreatFeed::where('provider', 'voipbl')->first();

        $this->feedEnabled = $feed?->enabled ?? false;
        $this->feedCountryMode = $feed?->country_mode ?? 'all';
        $this->feedCountriesInput = implode(', ', $feed?->countries ?? []);
        $this->feedSyncInterval = $feed?->sync_interval ?? 'daily';
    }

    /**
     * Persist the threat feed configuration from the tab form.
     *
     * Country codes are normalized to uppercase two-letter ISO values and
     * capped at 50 entries; any malformed token refuses the whole save with
     * an inline error instead of storing a half-parsed list.
     */
    public function saveFeedSettings(): void
    {
        $this->ensureFeedManagePermission();

        $this->validate([
            'feedCountryMode' => ['required', 'in:all,blacklist,whitelist'],
            'feedSyncInterval' => ['required', 'in:hourly,4_hours,12_hours,daily'],
        ]);

        $countries = $this->parseCountryCodes($this->feedCountriesInput);

        if ($countries === null) {
            $this->addError('feedCountriesInput', (string) __('admin.security_threat_feed_countries_invalid'));

            return;
        }

        $feed = $this->voipblFeed();
        $feed->update([
            'enabled' => $this->feedEnabled,
            'country_mode' => $this->feedCountryMode,
            'countries' => $countries,
            'sync_interval' => $this->feedSyncInterval,
        ]);

        SecurityAuditLog::record(
            action: 'threat_feed_updated',
            ipAddress: $this->adminIp,
            description: "Threat feed '{$feed->name}' settings updated (mode: {$feed->country_mode}, interval: {$feed->sync_interval}, ".($feed->enabled ? 'enabled' : 'disabled').').',
            details: ['provider' => $feed->provider, 'countries' => $countries],
            adminId: Auth::guard('admin')->id(),
        );

        $this->notifySuccess((string) __('admin.security_threat_feed_saved'));
    }

    /**
     * Run an immediate forced sync of the public feed and report the result.
     */
    public function syncThreatFeedNow(): void
    {
        $this->ensureFeedManagePermission();

        $feed = $this->voipblFeed();
        $result = app(ThreatFeedManager::class)->sync($feed, force: true);

        match ($result->status) {
            'success' => $this->notifySuccess((string) __('admin.security_threat_feed_sync_success', ['count' => $result->entriesCount])),
            'not_modified' => $this->notifySuccess((string) __('admin.security_threat_feed_sync_uptodate')),
            default => $this->notifyError((string) __('admin.security_threat_feed_sync_failed', ['error' => (string) $result->error])),
        };

        $this->loadFeedState();
    }

    /**
     * Flush the feed's kernel elements without disabling the feed.
     *
     * The documented escape hatch for the day a feed ships a false positive
     * that blocks a real provider: the sets empty immediately through the
     * helper, the feed configuration stays untouched, and the next sync
     * repopulates the elements.
     */
    public function removeAllFeedBlocks(): void
    {
        $this->ensureFeedManagePermission();

        app(ThreatFeedIngestionService::class)->clear();

        if (! app(SecurityExecutorInterface::class)->updateThreatFeed()) {
            $this->notifyError((string) __('admin.security_threat_feed_remove_blocks_failed'));

            return;
        }

        SecurityAuditLog::record(
            action: 'threat_feed_blocks_removed',
            ipAddress: $this->adminIp,
            description: 'All threat feed kernel elements removed from the Security Center; the feed configuration was left enabled.',
            adminId: Auth::guard('admin')->id(),
        );

        $this->notifySuccess((string) __('admin.security_threat_feed_blocks_removed'));
    }

    /**
     * Turn the automatic scanner-ban enforcement on or off.
     *
     * The toggle gates future bans only — detection and recording are always
     * on, and no firewall rule changes, so no ruleset re-apply happens here.
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
     *
     * Only the documented choices are accepted (1 hour, 24 hours, 7 days,
     * or permanent); anything else is refused with an alert and the stored
     * value stays untouched.
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
     * Add a custom scanner signature (high-confidence by definition).
     */
    public function addScannerSignature(): void
    {
        $validated = SipScannerSignatures::validateCustomEntry($this->newScannerSignature);

        if ($validated === null) {
            $this->addError('newScannerSignature', (string) __('admin.security_scanner_signature_invalid'));

            return;
        }

        // Case-insensitive dedupe against both the custom list and the
        // curated base, mirroring the registry's own sanitization.
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
     *
     * Only entries that are actually stored can be removed; the curated
     * base list is read-only and never touchable from the panel.
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
     *
     * Delegates to the ban service so the database row, the audit entry,
     * the kernel set, and the conntrack flush stay consistent.
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
            // The address is trusted or no longer valid; nothing was blocked.
            $this->notifyError((string) __('admin.security_scanner_incident_refused'));

            return;
        }

        $this->notifySuccess((string) __('admin.security_scanner_incident_banned'));
    }

    /**
     * Read the STAGE 7 drop counter from the live kernel ruleset.
     *
     * Returns null when the helper cannot report the ruleset (unprivileged
     * runs, tests, butler unavailable) or the rule is absent — the panel
     * renders a dash instead of pretending the count is zero.
     */
    public function feedDropCounter(): ?int
    {
        $status = app(SecurityExecutorInterface::class)->status();

        if ($status === '' || preg_match('/ip saddr @threat_feed_ips counter packets (\d+)/', $status, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * The VoIPBL feed configuration row, created lazily on first write.
     */
    private function voipblFeed(): SecurityThreatFeed
    {
        return SecurityThreatFeed::firstOrCreate(
            ['provider' => 'voipbl'],
            ['name' => 'VoIPBL'],
        );
    }

    /**
     * Parse the comma-separated country-code input.
     *
     * Returns an uppercase two-letter list (maximum 50 entries), or null
     * when any token is malformed — the caller reports the inline error.
     *
     * @return array<int, string>|null
     */
    private function parseCountryCodes(string $input): ?array
    {
        $tokens = preg_split('/[\s,]+/', trim($input)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

        $codes = [];
        foreach ($tokens as $token) {
            $code = strtoupper($token);

            if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                return null;
            }

            $codes[] = $code;
        }

        $codes = array_values(array_unique($codes));

        return count($codes) > 50 ? null : $codes;
    }

    /**
     * Refuse feed mutations without the dedicated manage permission.
     */
    private function ensureFeedManagePermission(): void
    {
        $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        abort_unless($actor !== null && $actor->hasPermission('security.threat-feeds.manage'), 403);
    }

    /**
     * Move a pre-filter stage one position earlier in the evaluation order.
     */
    public function movePreFilterUp(string $stage): void
    {
        $this->movePreFilterStage($stage, -1);
    }

    /**
     * Move a pre-filter stage one position later in the evaluation order.
     */
    public function movePreFilterDown(string $stage): void
    {
        $this->movePreFilterStage($stage, 1);
    }

    /**
     * Reset the stored pre-filter order to the recommended default.
     *
     * The escape hatch that always restores a known-safe evaluation order,
     * mirroring the custom-rule reordering flow: persist, audit, apply.
     */
    public function resetPreFilterOrder(): void
    {
        $order = SecurityConfigGenerator::DEFAULT_PRE_FILTER_ORDER;

        DB::transaction(function () use ($order): void {
            SecuritySetting::updateOrCreate(['key' => 'pre_filter_order'], ['value' => json_encode($order)]);
        });

        SecurityAuditLog::record(
            action: 'pre_filter_order_reset',
            ipAddress: $this->adminIp,
            description: 'Pre-filter order reset to the recommended default: '.implode(', ', $order),
            adminId: Auth::guard('admin')->id(),
        );

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_rule_reordered'));
        }
    }

    /**
     * Swap a pre-filter stage with its neighbour, validating the result first.
     *
     * Mirrors the custom-rule reordering ergonomics: the pinned loopback row
     * refuses with an explanation, an unsafe result is rejected with the
     * validator's plain-language reason, and a successful move persists the
     * order, records an audit entry, and re-applies the ruleset.
     *
     * @param  string  $stage  Stage key to move (fixed vocabulary)
     * @param  int  $direction  -1 moves earlier, +1 moves later
     */
    private function movePreFilterStage(string $stage, int $direction): void
    {
        // The loopback stage is pinned first — it is how the server talks to
        // its own database, cache, and phone engine.
        if ($stage === 'loopback') {
            $this->notifyError((string) __('admin.security_prefilter_loopback_pinned'));

            return;
        }

        $generator = app(SecurityConfigGenerator::class);

        try {
            $order = $generator->preFilterOrder();
        } catch (\RuntimeException $e) {
            $this->notifyError($e->getMessage());

            return;
        }

        $index = array_search($stage, $order, true);
        if ($index === false) {
            $this->notifyError((string) __('admin.security_prefilter_stage_unknown', ['stage' => $stage]));

            return;
        }

        $previousOrder = $order;
        $target = $index + $direction;
        if ($target < 0 || $target >= count($order)) {
            // Already at the edge of the list: nothing to do.
            return;
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
        $order = array_values($order);

        try {
            $generator->assertValidPreFilterOrder($order);
        } catch (\RuntimeException $e) {
            $this->notifyError($e->getMessage());

            return;
        }

        DB::transaction(function () use ($order): void {
            SecuritySetting::updateOrCreate(['key' => 'pre_filter_order'], ['value' => json_encode($order)]);
        });

        SecurityAuditLog::record(
            action: 'pre_filter_reordered',
            ipAddress: $this->adminIp,
            description: 'Pre-filter order changed from ['
                .implode(', ', $previousOrder)
                .'] to ['
                .implode(', ', $order)
                .']',
            details: ['before' => $previousOrder, 'after' => $order],
            adminId: Auth::guard('admin')->id(),
        );

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_rule_reordered'));
        }
    }

    /**
     * Real-time push-event listener and status updater.
     */
    // Subscribe to the private security alerts channel over Laravel Echo.
    // routes/channels.php only authorizes panel users with the security.view
    // permission, so public WebSocket clients never receive these alerts.
    #[On('refresh-security')]
    #[On('echo-private:security.alerts,.SecurityBanUpdated')]
    #[On('echo-private:security.alerts,.SecurityIncidentLogged')]
    #[On('echo-private:security.alerts,.FirewallRulesetUpdated')]
    public function refreshStatus(?LockoutGuardService $lockoutGuard = null): void
    {
        $lockoutGuard ??= app(LockoutGuardService::class);
        $this->checkAdminIpStatus($lockoutGuard);
        $this->loadSettings();
        $this->loadFeedState();

        // Livewire maps one handler per event name, so the observed-kernel
        // refresh rides along here to keep the drift banner current on every
        // pushed update without polling.
        $this->refreshLiveFirewallPolicy();
        $this->refreshFirewallSyncState();
    }

    /**
     * Add a new IP or CIDR subnet to the permanent blacklist.
     */
    public function addBlacklistIp(LockoutGuardService $lockoutGuard, SecurityBanServiceInterface $banService): void
    {
        $this->validate([
            'newBlacklistIp' => [
                'required',
                // Both IPv4 and IPv6 entries (with optional CIDR) are validated
                // by the shared rule, which also rejects malformed values.
                new ValidFirewallAddress((string) __('admin.security_ip_format_invalid')),
            ],
            'newBlacklistDescription' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->newBlacklistIp);

        // Whitelisted addresses are immune to blocking, and the kernel drop
        // rules evaluate before the whitelist bypass, so blacklisting a
        // trusted address would silently defeat its protection (and can lock
        // out the administrator's own session). Refuse with an inline error.
        if ($lockoutGuard->isWhitelisted($ip)) {
            $this->addError('newBlacklistIp', (string) __('admin.security_cannot_blacklist_whitelisted', ['ip' => $ip]));

            return;
        }

        if (SecurityIpList::where('type', 'blacklist')->where('ip_address', $ip)->exists()) {
            $this->addError('newBlacklistIp', 'This IP address is already present in the blacklist.');

            return;
        }

        SecurityIpList::create([
            'type' => 'blacklist',
            'ip_address' => $ip,
            'description' => $this->newBlacklistDescription ? trim($this->newBlacklistDescription) : null,
        ]);

        $this->newBlacklistIp = '';
        $this->newBlacklistDescription = '';
        $this->checkAdminIpStatus($lockoutGuard);

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            // The block only filters new flows (blocklists sit behind the
            // stateful fast path), so sever the address's live sessions now
            // that its block is live in the kernel (invariant 4).
            $banService->flushConntrack($ip);
            $this->notifySuccess((string) __('admin.security_ip_added'));
        }
    }

    /**
     * Add a new IP or CIDR subnet to the trusted whitelist.
     */
    public function addWhitelistIp(LockoutGuardService $lockoutGuard): void
    {
        $this->validate([
            'newWhitelistIp' => [
                'required',
                // Both IPv4 and IPv6 entries (with optional CIDR) are validated
                // by the shared rule, which also rejects malformed values.
                new ValidFirewallAddress((string) __('admin.security_ip_format_invalid')),
            ],
            'newWhitelistDescription' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->newWhitelistIp);

        if (SecurityIpList::where('type', 'whitelist')->where('ip_address', $ip)->exists()) {
            $this->addError('newWhitelistIp', 'This IP address is already present in the whitelist.');

            return;
        }

        SecurityIpList::create([
            'type' => 'whitelist',
            'ip_address' => $ip,
            'description' => $this->newWhitelistDescription ? trim($this->newWhitelistDescription) : null,
        ]);

        $this->newWhitelistIp = '';
        $this->newWhitelistDescription = '';
        $this->checkAdminIpStatus($lockoutGuard);

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_ip_added'));
        }
    }

    /**
     * Delete an entry from the IP lists table.
     */
    public function deleteIp(int $id, LockoutGuardService $lockoutGuard): void
    {
        $entry = SecurityIpList::findOrFail($id);

        // Remember the entry so a refused apply can be rolled back: removing
        // the administrator's own trusted address while the default policy
        // blocks would lock them out, so the guard refuses the apply — and
        // the panel must not then claim the address is gone while the kernel
        // still trusts it.
        $attributes = $entry->only(['type', 'ip_address', 'description']);

        $entry->delete();

        $this->checkAdminIpStatus($lockoutGuard);

        if (! $this->autoApplyFirewallRuleset($lockoutGuard)) {
            SecurityIpList::create($attributes);

            return;
        }

        $this->notifySuccess((string) __('admin.security_ip_deleted'));
    }

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
     * Promote an active ban to the permanent Trusted whitelist.
     */
    public function promoteToWhitelist(string $ip, SecurityBanServiceInterface $banService, LockoutGuardService $lockoutGuard): void
    {
        if (method_exists($banService, 'promoteToWhitelist')) {
            $banService->promoteToWhitelist($ip, 'Promoted from active threats by administrator');
        } else {
            $banService->unban($ip);
            SecurityIpList::updateOrCreate(
                ['type' => 'whitelist', 'ip_address' => $ip],
                ['description' => 'Promoted from active threats by administrator']
            );
        }

        $this->checkAdminIpStatus($lockoutGuard);

        if ($this->autoApplyFirewallRuleset($lockoutGuard)) {
            $this->notifySuccess((string) __('admin.security_promoted_whitelist'));
        }
    }

    /**
     * Promote an active ban to the permanent Blocked blacklist.
     */
    public function promoteToBlacklist(string $ip, SecurityBanServiceInterface $banService): void
    {
        if (method_exists($banService, 'promoteToBlacklist')) {
            $banService->promoteToBlacklist($ip, 'Promoted from active threats to permanent blacklist');
        } else {
            $banService->unban($ip);
            SecurityIpList::updateOrCreate(
                ['type' => 'blacklist', 'ip_address' => $ip],
                ['description' => 'Promoted from active threats to permanent blacklist']
            );
        }

        if ($this->autoApplyFirewallRuleset()) {
            // Sever the promoted address's live sessions now that its
            // permanent block is live in the kernel (invariant 4).
            $banService->flushConntrack($ip);
            $this->notifySuccess((string) __('admin.security_promoted_blacklist'));
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
     *
     * Whitelisted addresses are immune to every blocking action, so attempts
     * to ban one are reported as a friendly alert instead of a raw exception.
     */
    public function manualBan(SecurityBanServiceInterface $banService, ?LockoutGuardService $lockoutGuard = null): void
    {
        $lockoutGuard ??= app(LockoutGuardService::class);

        $this->validate([
            'manualBanIp' => [
                'required',
                // Kernel ban sets hold single addresses only, so CIDR ranges
                // are rejected; both address families are fully supported.
                new ValidFirewallAddress((string) __('admin.security_ban_ip_format_invalid'), allowCidr: false),
            ],
            'manualBanDuration' => ['required', 'integer'],
            'manualBanReason' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->manualBanIp);
        $reason = $this->manualBanReason ? trim($this->manualBanReason) : 'Manual administrative ban';

        // The permanent option writes straight to the blacklist — which the
        // kernel evaluates before the whitelist bypass — so whitelisted
        // addresses must be refused here as well as in the transient ban path.
        if ($lockoutGuard->isWhitelisted($ip)) {
            // Mirror the success path: close the dialog immediately and report
            // the refusal as a red alert in the top-right toast layer.
            $this->showManualBanModal = false;
            $this->notifyError((string) __('admin.security_cannot_ban_whitelisted', ['ip' => $ip]));

            return;
        }

        // Permanent manual bans are stored in the blacklist rather than as a
        // timed kernel ban, so remember which path ran: only that path needs
        // the conntrack flush once the ruleset applies. Timed bans flush
        // inside SecurityBanService::ban() instead.
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
                // The ban service guard is authoritative and can reject the
                // address even after the pre-check; close the dialog and show
                // the same red toast instead of an unhandled exception page.
                $this->showManualBanModal = false;
                $this->notifyError((string) __('admin.security_cannot_ban_whitelisted', ['ip' => $ip]));

                return;
            }
        }

        $this->showManualBanModal = false;

        // Report the ban as saved only once the kernel accepted the ruleset.
        if ($this->autoApplyFirewallRuleset()) {
            // Sever live sessions for a permanent blacklist entry now that
            // its block is live in the kernel (invariant 4).
            if ($permanentBlacklistEntry) {
                $banService->flushConntrack($ip);
            }

            $this->notifySuccess((string) __('admin.security_manual_ban_success'));
        }
    }

    /**
     * Move a rule higher in priority (lower sequence number).
     */
    public function moveRuleUp(int $ruleId): void
    {
        $rule = SecurityRule::findOrFail($ruleId);
        $previous = SecurityRule::where('sequence', '<', $rule->sequence)
            ->orderBy('sequence', 'desc')
            ->first();

        if ($previous !== null) {
            DB::transaction(function () use ($rule, $previous): void {
                $temp = $rule->sequence;
                $rule->sequence = $previous->sequence;
                $previous->sequence = $temp;
                $rule->save();
                $previous->save();
            });

            if ($this->autoApplyFirewallRuleset()) {
                $this->notifySuccess((string) __('admin.security_rule_reordered'));
            }
        }
    }

    /**
     * Move a rule lower in priority (higher sequence number).
     */
    public function moveRuleDown(int $ruleId): void
    {
        $rule = SecurityRule::findOrFail($ruleId);
        $next = SecurityRule::where('sequence', '>', $rule->sequence)
            ->orderBy('sequence', 'asc')
            ->first();

        if ($next !== null) {
            DB::transaction(function () use ($rule, $next): void {
                $temp = $rule->sequence;
                $rule->sequence = $next->sequence;
                $next->sequence = $temp;
                $rule->save();
                $next->save();
            });

            if ($this->autoApplyFirewallRuleset()) {
                $this->notifySuccess((string) __('admin.security_rule_reordered'));
            }
        }
    }

    /**
     * Toggle a firewall rule between enabled and disabled.
     */
    public function toggleRule(int $ruleId): void
    {
        $rule = SecurityRule::findOrFail($ruleId);
        $rule->enabled = ! $rule->enabled;
        $rule->save();

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_rule_updated'));
        }
    }

    /**
     * Delete a firewall rule from the sequential list.
     */
    public function deleteRule(int $ruleId): void
    {
        $rule = SecurityRule::findOrFail($ruleId);
        $rule->delete();

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_rule_deleted'));
        }
    }

    /**
     * 1-click quick-rule: add an allowed service directly from the PBX port catalog.
     */
    public function addServiceFromCatalog(int $serviceId): void
    {
        $service = SecurityService::findOrFail($serviceId);
        $maxSeq = (int) SecurityRule::max('sequence') ?: 0;

        SecurityRule::create([
            'sequence' => $maxSeq + 10,
            'description' => "Allow {$service->name}",
            'source_ip' => 'any',
            'service_id' => $service->id,
            'action' => 'accept',
            'enabled' => true,
        ]);

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_rule_created'));
        }
    }

    /**
     * Open modal to customize a core PBX system service.
     */
    public function openEditSystemServiceModal(int $serviceId): void
    {
        $service = SecurityService::findOrFail($serviceId);
        $this->editingSystemServiceId = $service->id;
        $this->systemServiceName = $service->name;
        $this->systemServiceDescription = (string) ($service->description ?? '');
        $this->systemServicePortRange = (string) $service->port_range;
        $this->systemServiceProtocol = (string) $service->protocol;
        $this->systemServiceSourceIp = (string) ($service->source_ip ?? 'any');
        $this->systemServiceEnabled = (bool) $service->enabled;

        if ($service->protocol === 'icmp') {
            $this->systemServiceRateLimit = $service->rate_limit ?? 5;
            $this->systemServiceBurst = $service->burst ?? 5;
            $this->systemServiceRateLimitEnabled = ($service->rate_limit !== null && $service->rate_limit > 0);
        } else {
            $this->systemServiceRateLimit = null;
            $this->systemServiceBurst = null;
            $this->systemServiceRateLimitEnabled = false;
        }

        $this->showSystemServiceModal = true;
    }

    /**
     * Save customized settings for a core PBX system service.
     */
    public function saveSystemService(?LockoutGuardService $lockoutGuard = null): void
    {
        $lockoutGuard ??= app(LockoutGuardService::class);

        $this->validate([
            'systemServicePortRange' => ['required', 'string', 'max:100'],
            'systemServiceProtocol' => ['required', 'in:tcp,udp,both,icmp'],
            'systemServiceSourceIp' => ['required', 'string', 'max:100'],
            'systemServiceEnabled' => ['boolean'],
            'systemServiceRateLimitEnabled' => ['boolean'],
            'systemServiceRateLimit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'systemServiceBurst' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $service = SecurityService::findOrFail($this->editingSystemServiceId);

        // Zero-lockout protection check for administrative portal / SSH
        if (in_array($service->name, ['Web Admin Portal', 'SSH Console'], true)) {
            $source = trim($this->systemServiceSourceIp);
            $isRestricted = ($source !== '' && $source !== 'any' && $source !== '0.0.0.0/0');
            $isLoopback = in_array($this->adminIp, ['127.0.0.1', '::1'], true);
            $isWhitelisted = $lockoutGuard->isWhitelisted($this->adminIp);

            if (! $this->systemServiceEnabled && ! $isLoopback && ! $isWhitelisted) {
                $this->notifyError("Zero-Lockout Safety Alert: Disabling {$service->name} would disconnect your active administrator session from {$this->adminIp}. Please whitelist your IP address before disabling this service.");

                return;
            }

            if ($isRestricted && ! $isLoopback && ! $isWhitelisted) {
                $matchesSource = false;
                try {
                    $matchesSource = IpUtils::checkIp($this->adminIp, [$source]);
                } catch (\Throwable) {
                    $matchesSource = false;
                }

                if (! $matchesSource) {
                    $this->notifyError("Zero-Lockout Safety Alert: Restricting {$service->name} to {$source} would lock out your active administrator session from {$this->adminIp}. Please whitelist your IP address before applying this restriction.");

                    return;
                }
            }
        }

        $rateLimit = null;
        $burst = null;
        if ($this->systemServiceProtocol === 'icmp') {
            if ($this->systemServiceRateLimitEnabled) {
                $rateLimit = $this->systemServiceRateLimit ?: 5;
                $burst = $this->systemServiceBurst ?: 5;
            }
        }

        $service->update([
            'port_range' => str_replace(':', '-', trim($this->systemServicePortRange)),
            'protocol' => $this->systemServiceProtocol,
            'source_ip' => trim($this->systemServiceSourceIp),
            'rate_limit' => $rateLimit,
            'burst' => $burst,
            'enabled' => $this->systemServiceEnabled,
        ]);

        $this->showSystemServiceModal = false;

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_service_updated'));
        }
    }

    /**
     * Toggle a core PBX system service between enabled and disabled.
     */
    public function toggleSystemService(int $serviceId, ?LockoutGuardService $lockoutGuard = null): void
    {
        $lockoutGuard ??= app(LockoutGuardService::class);
        $service = SecurityService::findOrFail($serviceId);

        // Prevent disabling Web Admin Portal or SSH Console if admin would be locked out
        if ($service->enabled && in_array($service->name, ['Web Admin Portal', 'SSH Console'], true)) {
            $isLoopback = in_array($this->adminIp, ['127.0.0.1', '::1'], true);
            $isWhitelisted = $lockoutGuard->isWhitelisted($this->adminIp);

            if (! $isLoopback && ! $isWhitelisted) {
                $this->notifyError("Zero-Lockout Safety Alert: Disabling {$service->name} would disconnect your active administrator session from {$this->adminIp}. Please whitelist your IP address before disabling this service.");

                return;
            }
        }

        $service->enabled = ! $service->enabled;
        $service->save();

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_service_updated'));
        }
    }

    /**
     * Reset a core PBX system service to its factory default ports, protocol, and unrestricted access.
     */
    public function resetSystemServiceToDefault(int $serviceId): void
    {
        $service = SecurityService::findOrFail($serviceId);
        $default = $service->getDefaultConfig();

        if ($default !== null) {
            $service->update([
                'port_range' => $default['port_range'],
                'protocol' => $default['protocol'],
                'source_ip' => $default['source_ip'],
                'rate_limit' => $default['rate_limit'] ?? null,
                'burst' => $default['burst'] ?? null,
                'enabled' => true,
            ]);

            if ($this->editingSystemServiceId === $serviceId) {
                $this->systemServicePortRange = $default['port_range'];
                $this->systemServiceProtocol = $default['protocol'];
                $this->systemServiceSourceIp = $default['source_ip'];
                $this->systemServiceRateLimit = $default['rate_limit'] ?? null;
                $this->systemServiceBurst = $default['burst'] ?? null;
                $this->systemServiceRateLimitEnabled = isset($default['rate_limit']);
                $this->systemServiceEnabled = true;
            }

            if ($this->autoApplyFirewallRuleset()) {
                $this->notifySuccess((string) __('admin.security_service_reset_success'));
            }
        }
    }

    /**
     * Open modal to create or edit a custom firewall rule.
     */
    public function openCustomRuleModal(?int $ruleId = null): void
    {
        $this->editingRuleId = $ruleId;

        if ($ruleId !== null) {
            $rule = SecurityRule::findOrFail($ruleId);
            $this->ruleDescription = $rule->description;
            $this->ruleSourceIp = $rule->source_ip;
            $this->ruleServiceId = $rule->service_id;
            $this->ruleCustomPort = (string) ($rule->custom_port ?? '');
            $this->ruleCustomProtocol = (string) ($rule->custom_protocol ?? 'tcp');
            $this->ruleAction = $rule->action;
            $this->ruleEnabled = (bool) $rule->enabled;
        } else {
            $this->ruleDescription = '';
            $this->ruleSourceIp = 'any';
            $this->ruleServiceId = null;
            $this->ruleCustomPort = '';
            $this->ruleCustomProtocol = 'tcp';
            $this->ruleAction = 'accept';
            $this->ruleEnabled = true;
        }

        $this->showRuleModal = true;
    }

    /**
     * Save custom firewall rule (create or update).
     */
    public function saveCustomRule(): void
    {
        $this->validate([
            'ruleDescription' => ['required', 'string', 'max:255'],
            'ruleSourceIp' => ['required', 'string', 'max:100'],
            'ruleServiceId' => ['nullable', 'exists:security_services,id'],
            'ruleCustomPort' => ['nullable', 'required_without:ruleServiceId', 'string', 'max:100'],
            'ruleCustomProtocol' => ['nullable', 'in:all,tcp,udp'],
            'ruleAction' => ['required', 'in:accept,drop'],
            'ruleEnabled' => ['boolean'],
        ]);

        $data = [
            'description' => trim($this->ruleDescription),
            'source_ip' => trim($this->ruleSourceIp),
            'service_id' => $this->ruleServiceId ?: null,
            'custom_port' => $this->ruleServiceId ? null : str_replace(':', '-', trim($this->ruleCustomPort)),
            'custom_protocol' => $this->ruleServiceId ? null : $this->ruleCustomProtocol,
            'action' => $this->ruleAction,
            'enabled' => $this->ruleEnabled,
        ];

        if ($this->editingRuleId !== null) {
            $rule = SecurityRule::findOrFail($this->editingRuleId);
            $rule->update($data);
        } else {
            $maxSeq = (int) SecurityRule::max('sequence') ?: 0;
            $data['sequence'] = $maxSeq + 10;
            SecurityRule::create($data);
        }

        $this->showRuleModal = false;

        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_rule_created'));
        }
    }

    /**
     * Automatically compile and atomically apply firewall changes to the Linux kernel.
     */
    public function autoApplyFirewallRuleset(
        ?LockoutGuardService $lockoutGuard = null,
        ?SecurityConfigGenerator $generator = null,
        ?SecurityExecutorInterface $executor = null,
        bool $force = false
    ): bool {
        $lockoutGuard ??= app(LockoutGuardService::class);
        $generator ??= app(SecurityConfigGenerator::class);
        $executor ??= app(SecurityExecutorInterface::class);

        // 1. Zero-lockout preflight validation: the administrator's own
        //    connection AND the server's loopback services (database, cache)
        //    must both survive the ruleset that is about to be applied.
        if (! $force) {
            try {
                if ($this->adminIp !== null && $this->adminIp !== '') {
                    $lockoutGuard->assertSafe($this->adminIp, $this->firewallDefaultPolicy);
                }

                $lockoutGuard->assertLocalServicesSafe();
            } catch (LockoutException $e) {
                $this->notifyError($e->getMessage());

                return false;
            }
        }

        // 2. Write pending configuration
        try {
            $pendingFile = $generator->writePending();
        } catch (\Throwable $e) {
            $this->notifyError("Failed to write pending configuration: {$e->getMessage()}");

            return false;
        }

        // 3. Preflight syntax check
        if (! $generator->validateSyntax($pendingFile)) {
            $this->notifyError('Pending firewall configuration failed nftables syntax validation. Kernel ruleset was not modified.');

            return false;
        }

        // 4. Apply via bounded host helper
        if (! $executor->apply()) {
            $this->notifyError('Failed to apply firewall ruleset via bounded helper.');

            return false;
        }

        // 5. Audit log and reset pending changes counter
        SecurityAuditLog::record(
            action: 'firewall_applied_ui',
            ipAddress: $this->adminIp,
            description: 'Firewall ruleset applied automatically from Security Command Center UI',
            adminId: Auth::guard('admin')->id()
        );

        $this->pendingChangesCount = 0;
        SecuritySetting::updateOrCreate(['key' => 'pending_changes_count'], ['value' => '0']);

        // Broadcast real-time ruleset update over Laravel Reverb
        FirewallRulesetUpdated::dispatch('ui');

        // The kernel now runs the freshly applied ruleset; keep the drift
        // indicator truthful without a privileged re-read.
        $this->liveFirewallPolicy = $this->firewallDefaultPolicy;
        $this->firewallSyncState = 'verified';
        $this->firewallSyncAppliedAt = now()->toIso8601String();
        $this->firewallSyncReason = null;

        return true;
    }

    /**
     * Compile and atomically apply firewall changes to the Linux kernel via nftables.
     */
    public function applyFirewallChanges(
        ?LockoutGuardService $lockoutGuard = null,
        ?SecurityConfigGenerator $generator = null,
        ?SecurityExecutorInterface $executor = null,
        bool $force = false
    ): void {
        if ($this->autoApplyFirewallRuleset($lockoutGuard, $generator, $executor, $force)) {
            $this->notifySuccess((string) __('admin.security_firewall_applied_success'));
        }
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
     * Open the dedicated Default Inbound Policy form.
     */
    public function openDefaultPolicyForm(): void
    {
        // Reload only the persisted policy so the form always starts from the
        // saved state without discarding unsaved edits from other forms.
        $this->firewallDefaultPolicy = SecuritySetting::get('firewall_default_policy', 'drop') ?? 'drop';
        $this->showDefaultPolicyModal = true;
    }

    /**
     * Close the Default Inbound Policy form without saving.
     */
    public function closeDefaultPolicyForm(): void
    {
        $this->showDefaultPolicyModal = false;
    }

    /**
     * Save the default inbound firewall policy and apply it immediately.
     */
    public function saveDefaultPolicy(): void
    {
        $this->validate([
            'firewallDefaultPolicy' => ['required', 'in:drop,accept'],
        ]);

        // Persisting a blocking policy while the pre-filter is off would cut
        // the server's own loopback services (database, cache), so the change
        // is refused before anything is written; the dialog stays open so the
        // administrator can pick Allow All instead.
        if ($this->firewallDefaultPolicy === 'drop'
            && app(LockoutGuardService::class)->wouldDropLocalServices(proposedDefaultPolicy: 'drop')) {
            $this->notifyError((string) __('admin.security_default_policy_local_services_refused'));

            return;
        }

        // Remember the persisted policy so a refused apply can be rolled back.
        $previousPolicy = SecuritySetting::get('firewall_default_policy', 'drop') ?? 'drop';

        SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => $this->firewallDefaultPolicy]);

        // Mirror the manual ban flow: close the dialog first, then report the
        // outcome in the top-right toast layer above the page.
        $this->showDefaultPolicyModal = false;

        if (! $this->autoApplyFirewallRuleset()) {
            // The kernel rejected the change (lockout guard, syntax preflight,
            // or helper failure) and the active ruleset is untouched. Restore
            // the previous value so the UI never reports a policy the live
            // firewall is not actually running.
            SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => $previousPolicy]);
            $this->firewallDefaultPolicy = $previousPolicy;

            return;
        }

        $this->notifySuccess((string) __('admin.security_settings_saved'));
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

        // Only claim success when the firewall ruleset was actually applied:
        // autoApplyFirewallRuleset() already reports failures, and an
        // unconditional success message here would mask that error.
        if ($this->autoApplyFirewallRuleset()) {
            $this->notifySuccess((string) __('admin.security_settings_saved'));
        }
    }

    /**
     * Notify success message to user via feedback traits and session flash.
     */
    protected function notifySuccess(string $message): void
    {
        $this->showSuccess($message);
        session()->flash('status', $message);
    }

    /**
     * Notify error message to user via feedback traits and session flash.
     */
    protected function notifyError(string $message): void
    {
        $this->showError($message);
        session()->flash('error', $message);
    }

    /**
     * Increment the pending changes counter and persist it.
     */
    private function incrementPendingChanges(): void
    {
        $this->pendingChangesCount++;
        SecuritySetting::updateOrCreate(
            ['key' => 'pending_changes_count'],
            ['value' => (string) $this->pendingChangesCount]
        );
    }

    /**
     * Render the Security Command Center Blade view.
     */
    public function render(SecurityBanServiceInterface $banService): View
    {
        $blacklistIps = SecurityIpList::query()
            ->where('type', 'blacklist')
            ->when($this->blacklistSearch ?: $this->ipSearch, function ($query): void {
                $search = $this->blacklistSearch ?: $this->ipSearch;
                $query->where(function ($q) use ($search): void {
                    $q->where('ip_address', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('id', 'desc')
            ->get();

        $whitelistIps = SecurityIpList::query()
            ->where('type', 'whitelist')
            ->when($this->whitelistSearch ?: $this->ipSearch, function ($query): void {
                $search = $this->whitelistSearch ?: $this->ipSearch;
                $query->where(function ($q) use ($search): void {
                    $q->where('ip_address', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('id', 'desc')
            ->get();

        $activeBans = $banService->getActiveBans();

        $firewallRules = SecurityRule::with('service')
            ->orderBy('sequence', 'asc')
            ->get();

        $catalogServices = SecurityService::where('is_system', true)
            // Show ICMP rules first so the related group stays together in the table.
            ->orderByRaw("CASE WHEN protocol = 'icmp' THEN 0 ELSE 1 END, name ASC")
            ->get();

        $bannedCount = SecurityBan::active()->count();
        $whitelistCount = SecurityIpList::whitelist()->count();
        $blacklistCount = SecurityIpList::blacklist()->count();

        // The panel displays the same clamped limits the kernel is actually
        // running (zero or missing settings fall back to the defaults).
        $tftpLimits = SecurityConfigGenerator::tftpLimits();

        // Detected-but-unblocked scanner incidents: inactive incident rows
        // whose address is not already covered by an active ban.
        $scannerIncidents = SecurityBan::where('is_active', false)
            ->where('vector', 'sip_scanner')
            ->whereNotIn('ip_address', SecurityBan::active()->pluck('ip_address'))
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        return view('security::security-manager', [
            'blacklistIps' => $blacklistIps,
            'whitelistIps' => $whitelistIps,
            'activeBans' => $activeBans,
            'firewallRules' => $firewallRules,
            'catalogServices' => $catalogServices,
            'bannedCount' => $bannedCount,
            'whitelistCount' => $whitelistCount,
            'blacklistCount' => $blacklistCount,
            'preFilterRows' => $this->preFilterRows(),
            'threatFeed' => SecurityThreatFeed::where('provider', 'voipbl')->first(),
            'feedDropCounter' => $this->feedDropCounter(),
            'tftpDefense' => [
                'enabled' => $this->tftpDefenseEnabled,
                'rate_limit' => $tftpLimits['rate_limit'],
                'burst' => $tftpLimits['burst'],
                'counters' => $this->tftpDefenseCounters(),
            ],
            'sipScanner' => [
                'enforcement' => $this->sipScannerEnforcement,
                'ban_seconds' => $this->sipScannerBanSeconds,
                'defaults' => SipScannerSignatures::defaults(),
                'custom' => SipScannerSignatures::custom(),
                'incidents' => $scannerIncidents,
            ],
        ]);
    }
}
