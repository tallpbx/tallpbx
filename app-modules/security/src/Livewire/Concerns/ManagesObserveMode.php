<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Support\ObserveMetricsParser;

trait ManagesObserveMode
{
    public bool $firewallObserveMode = false;

    public bool $showObserveDrawer = false;

    /**
     * Turn the global observe mode on or off.
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
     * Open the Observed Traffic Activity drawer.
     */
    public function openObserveDrawer(): void
    {
        $this->showObserveDrawer = true;
    }

    /**
     * Close the Observed Traffic Activity drawer.
     */
    public function closeObserveDrawer(): void
    {
        $this->showObserveDrawer = false;
    }

    /**
     * Retrieve structured Observe Mode counters across all firewall stages.
     *
     * @return array{total_packets: int, total_bytes: int, stages: array}
     */
    public function observeCounters(): array
    {
        $status = app(SecurityExecutorInterface::class)->status();

        return ObserveMetricsParser::parseCounters($status);
    }

    /**
     * Retrieve recent kernel Observe Mode log events from system journal.
     *
     * @param  int  $limit  Max events to read (default 50)
     * @return array<int, array>
     */
    public function observeEvents(int $limit = 50): array
    {
        return app(SecurityExecutorInterface::class)->observeEvents($limit);
    }
}
