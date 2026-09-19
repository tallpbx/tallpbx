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
     * Add a new IP or CIDR subnet to the permanent blacklist.
     */
    public function addBlacklistIp(LockoutGuardService $lockoutGuard): void
    {
        $this->validate([
            'newBlacklistIp' => [
                'required',
                'string',
                'regex:/^(([0-9]{1,3}\.){3}[0-9]{1,3}(\/([0-9]|[1-2][0-9]|3[0-2]))?|([0-9a-fA-F:]+)(\/[0-9]+)?)$/',
            ],
            'newBlacklistDescription' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = trim($this->newBlacklistIp);

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
        $this->autoApplyFirewallRuleset($lockoutGuard);
        $this->notifySuccess((string) __('admin.security_ip_added'));
    }

    /**
     * Add a new IP or CIDR subnet to the trusted whitelist.
     */
    public function addWhitelistIp(LockoutGuardService $lockoutGuard): void
    {
        $this->validate([
            'newWhitelistIp' => [
                'required',
                'string',
                'regex:/^(([0-9]{1,3}\.){3}[0-9]{1,3}(\/([0-9]|[1-2][0-9]|3[0-2]))?|([0-9a-fA-F:]+)(\/[0-9]+)?)$/',
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
        $this->autoApplyFirewallRuleset($lockoutGuard);
        $this->notifySuccess((string) __('admin.security_ip_added'));
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
            'port_range' => trim($this->systemServicePortRange),
            'protocol' => $this->systemServiceProtocol,
            'source_ip' => trim($this->systemServiceSourceIp),
            'rate_limit' => $rateLimit,
            'burst' => $burst,
            'enabled' => $this->systemServiceEnabled,
        ]);

        $this->showSystemServiceModal = false;
        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_service_updated'));
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

        $this->autoApplyFirewallRuleset();
        $this->notifySuccess((string) __('admin.security_service_updated'));
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

            $this->autoApplyFirewallRuleset();
            $this->notifySuccess((string) __('admin.security_service_reset_success'));
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

        $ipLists = $this->ipListType === 'blacklist' ? $blacklistIps : $whitelistIps;

        $activeBans = $banService->getActiveBans();

        $firewallRules = SecurityRule::with('service')
            ->orderBy('sequence', 'asc')
            ->get();

        $catalogServices = SecurityService::where('is_system', true)
            ->orderByRaw("CASE WHEN protocol = 'icmp' THEN 0 ELSE 1 END, name ASC")
            ->get();

        $bannedCount = SecurityBan::active()->count();
        $whitelistCount = SecurityIpList::whitelist()->count();
        $blacklistCount = SecurityIpList::blacklist()->count();

        return view('security::security-manager', [
            'ipLists' => $ipLists,
            'blacklistIps' => $blacklistIps,
            'whitelistIps' => $whitelistIps,
            'activeBans' => $activeBans,
            'firewallRules' => $firewallRules,
            'catalogServices' => $catalogServices,
            'bannedCount' => $bannedCount,
            'whitelistCount' => $whitelistCount,
            'blacklistCount' => $blacklistCount,
        ]);
    }
}
