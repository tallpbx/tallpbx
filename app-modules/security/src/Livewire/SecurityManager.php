<?php

declare(strict_types=1);

namespace Modules\Security\Livewire;

use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Events\FirewallRulesetUpdated;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Livewire\Concerns\ManagesAllowBlockLists;
use Modules\Security\Livewire\Concerns\ManagesAttackProtection;
use Modules\Security\Livewire\Concerns\ManagesExternalBlocklists;
use Modules\Security\Livewire\Concerns\ManagesFirewallRules;
use Modules\Security\Livewire\Concerns\ManagesObserveMode;
use Modules\Security\Livewire\Concerns\ManagesPreFilters;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Services\FirewallSyncVerifier;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Services\SecurityConfigGenerator;
use Modules\Security\Support\SipScannerSignatures;

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
    use ManagesAllowBlockLists;
    use ManagesAttackProtection;
    use ManagesExternalBlocklists;
    use ManagesFirewallRules;
    use ManagesObserveMode;
    use ManagesPreFilters;

    /**
     * Active tab in the Security Command Center tab strip.
     *
     * Defaults to 'firewall-rules' as the primary management cockpit.
     * Exposed as ?tab= so deep links like /panel/security?tab=external-blocklists
     * open the matching panel.
     */
    #[Url(as: 'tab')]
    public string $activeTab = 'firewall-rules';

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

    /**
     * Master enable/disable switch for the host firewall.
     */
    public bool $firewallEnabled = true;

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
     * Resolve the expected inbound firewall policy given the current operational mode.
     *
     * In observe mode or when the whole firewall is disabled, the ruleset generator
     * intentionally compiles the input chain with 'accept' so traffic is not blocked.
     * In standard enforcing mode, it matches the configured default policy.
     */
    public function expectedFirewallPolicy(): string
    {
        return ($this->firewallEnabled && ! $this->firewallObserveMode)
            ? $this->firewallDefaultPolicy
            : 'accept';
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
     * Real-time push-event listener and status updater.
     */
    // Subscribe to the private security alerts channel over Laravel Echo.
    // routes/channels.php only authorizes panel users with the security.view
    // permission, so public WebSocket clients never receive these alerts.
    #[On('refresh-security')]
    #[On('echo-private:security.alerts,.SecurityBanUpdated')]
    #[On('echo-private:security.alerts,.SecurityIncidentLogged')]
    #[On('echo-private:security.alerts,.FirewallRulesetUpdated')]
    #[On('echo-private:security.alerts,.ObserveTrafficLogged')]
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
        $this->liveFirewallPolicy = $this->expectedFirewallPolicy();
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

        $threatFeed = SecurityThreatFeed::where('provider', 'voipbl')->first();

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
            'threatFeed' => $threatFeed,
            'externalBlocklist' => $threatFeed,
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
            'observeMetrics' => $this->observeCounters(),
            'observeEvents' => $this->showObserveDrawer ? $this->observeEvents(50) : [],
            'expectedFirewallPolicy' => $this->expectedFirewallPolicy(),
        ]);
    }
}
