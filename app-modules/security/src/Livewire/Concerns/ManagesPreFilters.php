<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Services\SecurityConfigGenerator;

trait ManagesPreFilters
{
    public bool $prefilterEnabled = true;

    public bool $showDisablePrefilterModal = false;

    /**
     * Turn defensive pre-filters on or off.
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
     * Open the confirmation modal when requesting to disable pre-filters.
     */
    public function confirmDisablePrefilter(): void
    {
        $this->showDisablePrefilterModal = true;
    }

    /**
     * Close the disable pre-filters confirmation modal without changes.
     */
    public function closeDisablePrefilterModal(): void
    {
        $this->showDisablePrefilterModal = false;
    }

    /**
     * Confirm and execute disabling the pre-filter pipeline.
     */
    public function executeDisablePrefilter(LockoutGuardService $lockoutGuard): void
    {
        $this->showDisablePrefilterModal = false;
        $this->setPrefilterEnabled(false, $lockoutGuard);
    }

    /**
     * Build the presentation rows for the sequential pre-filter stages.
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
                'manage' => ['label' => __('admin.security_threat_feed_manage'), 'tab' => 'external-blocklists', 'class' => 'text-error'],
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
}
