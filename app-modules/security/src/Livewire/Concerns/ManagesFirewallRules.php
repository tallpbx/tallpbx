<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Illuminate\Support\Facades\DB;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\LockoutGuardService;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Livewire component concern for managing packet filtering rules and port access.
 */
trait ManagesFirewallRules
{
    public string $firewallDefaultPolicy = 'drop';

    public bool $showDefaultPolicyModal = false;

    public bool $showRuleModal = false;

    public ?int $editingRuleId = null;

    public string $ruleDescription = '';

    public string $ruleSourceIp = 'any';

    public ?int $ruleServiceId = null;

    public string $ruleCustomPort = '';

    public string $ruleCustomProtocol = 'tcp';

    public string $ruleAction = 'accept';

    public bool $ruleEnabled = true;

    public bool $showSystemServiceModal = false;

    public ?int $editingSystemServiceId = null;

    public string $systemServiceName = '';

    public string $systemServiceDescription = '';

    public string $systemServicePortRange = '';

    public string $systemServiceProtocol = 'tcp';

    public string $systemServiceSourceIp = 'any';

    public bool $systemServiceEnabled = true;

    public ?int $systemServiceRateLimit = null;

    public ?int $systemServiceBurst = null;

    public bool $systemServiceRateLimitEnabled = false;

    /**
     * Open the dedicated Default Inbound Policy form.
     */
    public function openDefaultPolicyForm(): void
    {
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

        if ($this->firewallDefaultPolicy === 'drop'
            && app(LockoutGuardService::class)->wouldDropLocalServices(proposedDefaultPolicy: 'drop')) {
            $this->notifyError((string) __('admin.security_default_policy_local_services_refused'));

            return;
        }

        $previousPolicy = SecuritySetting::get('firewall_default_policy', 'drop') ?? 'drop';

        SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => $this->firewallDefaultPolicy]);

        $this->showDefaultPolicyModal = false;

        if (! $this->autoApplyFirewallRuleset()) {
            SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => $previousPolicy]);
            $this->firewallDefaultPolicy = $previousPolicy;

            return;
        }

        $this->notifySuccess((string) __('admin.security_settings_saved'));
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
}
