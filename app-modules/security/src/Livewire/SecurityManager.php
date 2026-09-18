<?php

declare(strict_types=1);

namespace Modules\Security\Livewire;

use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Services\SecurityConfigGenerator;

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
     * Active tab for IP management deck ('whitelist' or 'blacklist').
     */
    public string $ipListType = 'whitelist';

    /**
     * New IP or CIDR to add to the trusted/blocked list.
     */
    public string $newIp = '';

    /**
     * Optional label or note for the new IP entry.
     */
    public string $newIpDescription = '';

    /**
     * Search query for filtering IP entries.
     */
    public string $ipSearch = '';

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
     * Visibility state for the attack protection settings slide-over drawer.
     */
    public bool $showSettingsDrawer = false;

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

    public bool $firewallEnabled = true;

    public bool $attackProtectionEnabled = true;

    /**
     * Mount the component and load initial state from database.
     */
    public function mount(LockoutGuardService $lockoutGuard): void
    {
        $this->adminIp = request()->ip() ?? '127.0.0.1';
        $this->checkAdminIpStatus($lockoutGuard);
        $this->loadSettings();
    }

    /**
     * Check if the administrator's current connection is safe from lockout.
     */
    public function checkAdminIpStatus(LockoutGuardService $lockoutGuard): void
    {
        $this->isCurrentIpWhitelisted = $lockoutGuard->isWhitelisted($this->adminIp);
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
        $this->autoApplyFirewallRuleset($lockoutGuard);
        $this->notifySuccess((string) __('admin.security_ip_protected_success'));
    }

    /**
     * Real-time push-event listener and status updater.
     */
    #[On('refresh-security')]
    #[On('echo:security.alerts,.SecurityBanUpdated')]
    #[On('echo:security.alerts,.SecurityIncidentLogged')]
    #[On('echo:security.alerts,.FirewallRulesetUpdated')]
    public function refreshStatus(?LockoutGuardService $lockoutGuard = null): void
    {
        $lockoutGuard ??= app(LockoutGuardService::class);
        $this->checkAdminIpStatus($lockoutGuard);
        $this->loadSettings();
    }

    /**
     * Switch between Trusted (whitelist) and Blocked (blacklist) IP list tabs.
     */
    public function switchIpListType(string $type): void
    {
        if (in_array($type, ['whitelist', 'blacklist'], true)) {
            $this->ipListType = $type;
        }
    }

    /**
     * Add a new IP or CIDR subnet to the active list.
     */
    public function addIp(LockoutGuardService $lockoutGuard): void
    {
        $this->validate([
            'newIp' => [
                'required',
                'string',
                'regex:/^(([0-9]{1,3}\.){3}[0-9]{1,3}(\/([0-9]|[1-2][0-9]|3[0-2]))?|([0-9a-fA-F:]+)(\/[0-9]+)?)$/',
            ],
            'newIpDescription' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->newIp);

        // Check for duplicate entries
        if (SecurityIpList::where('type', $this->ipListType)->where('ip_address', $ip)->exists()) {
            $this->addError('newIp', 'This IP address is already present in this list.');

            return;
        }

        SecurityIpList::create([
            'type' => $this->ipListType,
            'ip_address' => $ip,
            'description' => $this->newIpDescription ? trim($this->newIpDescription) : null,
        ]);

        $this->newIp = '';
        $this->newIpDescription = '';
        $this->checkAdminIpStatus($lockoutGuard);
        $this->autoApplyFirewallRuleset($lockoutGuard);
        $this->notifySuccess((string) __('admin.security_ip_added'));
    }

    /**
     * Delete an entry from the IP lists table.
     */
    public function deleteIp(int $id, LockoutGuardService $lockoutGuard): void
    {
        $entry = SecurityIpList::findOrFail($id);
        $entry->delete();

        $this->checkAdminIpStatus($lockoutGuard);
        $this->autoApplyFirewallRuleset($lockoutGuard);
        $this->notifySuccess((string) __('admin.security_ip_deleted'));
    }

    /**
     * Lift an active ban on an attacker IP address.
     */
    public function unban(string $ip, SecurityBanServiceInterface $banService): void
    {
        $adminId = Auth::guard('admin')->id();
        $banService->unban($ip, is_int($adminId) ? $adminId : null);
        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_unbanned_success'));
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
        $this->autoApplyFirewallRuleset($lockoutGuard);
        $this->notifySuccess((string) __('admin.security_promoted_whitelist'));
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

        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_promoted_blacklist'));
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
    public function manualBan(SecurityBanServiceInterface $banService): void
    {
        $this->validate([
            'manualBanIp' => [
                'required',
                'string',
                'regex:/^(([0-9]{1,3}\.){3}[0-9]{1,3}|[0-9a-fA-F:]+)$/',
            ],
            'manualBanDuration' => ['required', 'integer'],
            'manualBanReason' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->manualBanIp);
        $reason = $this->manualBanReason ? trim($this->manualBanReason) : 'Manual administrative ban';

        if ($this->manualBanDuration === -1) {
            SecurityIpList::updateOrCreate(
                ['type' => 'blacklist', 'ip_address' => $ip],
                ['description' => $reason]
            );
        } else {
            $banService->ban($ip, 'manual', $reason, $this->manualBanDuration);
        }

        $this->autoApplyFirewallRuleset();
        $this->showManualBanModal = false;
        $this->notifySuccess((string) __('admin.security_manual_ban_success'));
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

            $this->autoApplyFirewallRuleset();
            $this->notifySuccess((string) __('admin.security_rule_reordered'));
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

            $this->autoApplyFirewallRuleset();
            $this->notifySuccess((string) __('admin.security_rule_reordered'));
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

        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_rule_updated'));
    }

    /**
     * Delete a firewall rule from the sequential list.
     */
    public function deleteRule(int $ruleId): void
    {
        $rule = SecurityRule::findOrFail($ruleId);
        $rule->delete();

        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_rule_deleted'));
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

        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_rule_created'));
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
            'custom_port' => $this->ruleServiceId ? null : trim($this->ruleCustomPort),
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
        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_rule_created'));
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

        // 1. Zero-lockout preflight validation
        if (! $force && $this->adminIp !== null && $this->adminIp !== '') {
            try {
                $lockoutGuard->assertSafe($this->adminIp, $this->firewallDefaultPolicy);
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
            'firewallDefaultPolicy' => ['required', 'in:drop,accept'],
        ]);

        SecuritySetting::updateOrCreate(['key' => 'max_retry'], ['value' => (string) $this->maxRetry]);
        SecuritySetting::updateOrCreate(['key' => 'find_time'], ['value' => (string) $this->findTime]);
        SecuritySetting::updateOrCreate(['key' => 'ban_time'], ['value' => (string) $this->banTime]);
        SecuritySetting::updateOrCreate(['key' => 'protect_sip'], ['value' => $this->protectSip ? '1' : '0']);
        SecuritySetting::updateOrCreate(['key' => 'protect_web'], ['value' => $this->protectWeb ? '1' : '0']);
        SecuritySetting::updateOrCreate(['key' => 'protect_ssh'], ['value' => $this->protectSsh ? '1' : '0']);
        SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => $this->firewallDefaultPolicy]);
        SecuritySetting::updateOrCreate(['key' => 'firewall_enabled'], ['value' => $this->firewallEnabled ? '1' : '0']);
        SecuritySetting::updateOrCreate(['key' => 'attack_protection_enabled'], ['value' => $this->attackProtectionEnabled ? '1' : '0']);

        $this->showSettingsDrawer = false;
        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_settings_saved'));
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
        $ipLists = SecurityIpList::query()
            ->where('type', $this->ipListType)
            ->when($this->ipSearch, function ($query): void {
                $query->where(function ($q): void {
                    $q->where('ip_address', 'like', "%{$this->ipSearch}%")
                        ->orWhere('description', 'like', "%{$this->ipSearch}%");
                });
            })
            ->orderBy('id', 'desc')
            ->get();

        $activeBans = $banService->getActiveBans();

        $firewallRules = SecurityRule::with('service')
            ->orderBy('sequence', 'asc')
            ->get();

        $catalogServices = SecurityService::where('is_system', true)
            ->orderBy('name', 'asc')
            ->get();

        $bannedCount = SecurityBan::active()->count();

        return view('security::security-manager', [
            'ipLists' => $ipLists,
            'activeBans' => $activeBans,
            'firewallRules' => $firewallRules,
            'catalogServices' => $catalogServices,
            'bannedCount' => $bannedCount,
        ]);
    }
}
