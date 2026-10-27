<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Firewall\LockoutGuardService;
use Modules\Security\Services\Geo\GeoBlockingService;
use Modules\Security\Support\IsoCountries;

/**
 * Livewire component concern for managing Geo-Blocking policies and country allocations.
 */
trait ManagesGeoBlocking
{
    public bool $geoEnabled = false;

    public string $geoMode = 'blacklist'; // 'blacklist' | 'whitelist'

    /** @var array<int, string> */
    public array $geoCountries = [];

    public string $geoSyncInterval = 'weekly';

    public string $geoCountrySearch = '';

    public bool $confirmingClearGeoRules = false;

    /**
     * Load Geo-Blocking state into Livewire properties.
     */
    public function loadGeoState(): void
    {
        $rule = SecurityGeoRule::instance();

        $this->geoEnabled = (bool) $rule->enabled;
        $this->geoMode = $rule->mode ?? 'blacklist';
        $this->geoCountries = is_array($rule->countries) ? $rule->countries : [];
        $this->geoSyncInterval = $rule->sync_interval ?? 'weekly';
    }

    /**
     * Toggle Geo-Blocking enabled status.
     */
    public function setGeoEnabled(bool $enabled): void
    {
        $this->ensureGeoManagePermission();

        $rule = SecurityGeoRule::instance();

        // Safety lockout check when enabling whitelist mode
        if ($enabled && $this->geoMode === 'whitelist') {
            $lockoutGuard = app(LockoutGuardService::class);
            if (! $lockoutGuard->isWhitelisted($this->adminIp)) {
                $this->notifyError((string) __('admin.security_geo_lockout_warning'));

                return;
            }

            // "Block All Except Selected" with nothing selected would arm a
            // drop-everything perimeter; refuse until a country is chosen.
            if (empty($this->geoCountries)) {
                $this->notifyError((string) __('admin.security_geo_whitelist_countries_required'));

                return;
            }

            // With pre-filters disarmed the trusted-whitelist accept stage is
            // absent from the ruleset, so the geo drop (stage 9.5) would fire
            // before any accept for this administrator's own connection.
            if (! SecuritySetting::getBoolean('prefilter_enabled', true)) {
                $this->notifyError((string) __('admin.security_geo_prefilter_required'));

                return;
            }
        }

        if (! $enabled) {
            $rule->enabled = false;
            $rule->save();
            $this->geoEnabled = false;

            // Regenerate the ruleset without the geo stage; roll back if the
            // kernel refuses so the panel never reports a state it is not running.
            if (! $this->regenerateAndApplyFirewall()) {
                $rule->enabled = true;
                $rule->save();
                $this->geoEnabled = true;

                return;
            }

            app(GeoBlockingService::class)->clear();

            SecurityAuditLog::record(
                action: 'geo_blocking_disabled',
                ipAddress: $this->adminIp,
                description: 'Geo-Blocking was disabled from the Security Center; kernel geo sets were cleared.',
                adminId: Auth::guard('admin')->id(),
            );

            $this->notifySuccess((string) __('admin.security_geo_disabled_notice'));
            $this->loadGeoState();

            return;
        }

        // Enabling: mirror the form state into the rule and populate the
        // kernel sets FIRST, so the perimeter chain armed moments later
        // by the ruleset apply never references empty sets — in whitelist
        // mode an empty allow-set would drop all new inbound traffic.
        // Snapshot the armed state so a refused enablement can restore it
        // without degrading a perimeter that is already running.
        $previousEnabled = (bool) $rule->enabled;
        $previousMode = $rule->mode;
        $previousCountries = $rule->countries;

        $rule->mode = $this->geoMode;
        $rule->countries = array_values(array_unique(array_map('strtoupper', $this->geoCountries)));
        $rule->enabled = false;
        $rule->save();

        $result = app(GeoBlockingService::class)->sync($rule, force: true);

        // Whitelist mode refuses to arm without its full allow-set.
        // Blacklist mode stays fail-open: an empty blocked set drops
        // nothing, so enabling with a failed sync is safe to proceed.
        if ($result['status'] !== 'success' && $rule->mode === 'whitelist') {
            // Restore the previously armed state AND re-promote its kernel
            // sets: the database must never claim a state the kernel is not
            // running (the failed sync never promoted anything, so the old
            // sets are still live — this just reconciles the record).
            $rule->enabled = $previousEnabled;
            $rule->mode = $previousMode ?? 'blacklist';
            $rule->countries = is_array($previousCountries) ? $previousCountries : [];
            $rule->save();

            $this->notifyError((string) __('admin.security_geo_sync_failed', ['error' => $result['error'] ?? 'Unknown error']));
            $this->loadGeoState();

            return;
        }

        $rule->enabled = true;
        $rule->save();
        $this->geoEnabled = true;

        if (! $this->regenerateAndApplyFirewall()) {
            // Roll back to previous state so the panel never claims an enabled state the
            // kernel is not actually running.
            $rule->enabled = $previousEnabled;
            $rule->mode = $previousMode ?? 'blacklist';
            $rule->countries = is_array($previousCountries) ? $previousCountries : [];
            $rule->save();
            $this->geoEnabled = $previousEnabled;

            if ($previousEnabled) {
                app(GeoBlockingService::class)->sync($rule);
            } else {
                app(GeoBlockingService::class)->clear();
            }

            $this->loadGeoState();

            return;
        }

        // Audit only after the outcome is known, including the sync result.
        SecurityAuditLog::record(
            action: 'geo_blocking_enabled',
            ipAddress: $this->adminIp,
            description: "Geo-Blocking was enabled (mode: {$rule->mode}, countries: ".count($rule->countries ?? []).", sync: {$result['status']}).",
            adminId: Auth::guard('admin')->id(),
        );

        if ($result['status'] === 'success') {
            $this->notifySuccess((string) __('admin.security_geo_enabled_notice'));
        } else {
            $this->notifyError((string) __('admin.security_geo_sync_failed', ['error' => $result['error'] ?? 'Unknown error']));
        }

        $this->loadGeoState();
    }

    /**
     * Save Geo-Blocking settings (mode, sync interval).
     */
    public function saveGeoSettings(): void
    {
        $this->ensureGeoManagePermission();

        $this->validate([
            'geoMode' => ['required', 'in:blacklist,whitelist'],
            'geoSyncInterval' => ['required', 'in:weekly,monthly,on_demand'],
            'geoCountries' => ['array'],
            'geoCountries.*' => ['string', 'size:2'],
        ]);

        if ($this->geoEnabled && $this->geoMode === 'whitelist') {
            $lockoutGuard = app(LockoutGuardService::class);
            if (! $lockoutGuard->isWhitelisted($this->adminIp)) {
                $this->notifyError((string) __('admin.security_geo_lockout_warning'));

                return;
            }

            // Same stage-9.5 lockout mechanics as enablement: switching an
            // armed rule into whitelist mode needs the trusted accept stage.
            if (! SecuritySetting::getBoolean('prefilter_enabled', true)) {
                $this->notifyError((string) __('admin.security_geo_prefilter_required'));

                return;
            }

            // An empty selection on an armed whitelist rule would empty the
            // allow-set while the running chain still drops everything not
            // in it — refuse before anything is persisted.
            if (empty($this->geoCountries)) {
                $this->notifyError((string) __('admin.security_geo_whitelist_countries_required'));

                return;
            }
        }

        $rule = SecurityGeoRule::instance();

        // Snapshot for rollback: switching an armed perimeter must never
        // leave it running with a partial or missing allow-set, and a
        // refused change must not half-apply the new sync interval either.
        $previousMode = $rule->mode;
        $previousCountries = $rule->countries;
        $previousInterval = $rule->sync_interval;

        $rule->mode = $this->geoMode;
        $rule->sync_interval = $this->geoSyncInterval;
        $rule->countries = array_values(array_unique(array_map('strtoupper', $this->geoCountries)));
        $rule->save();

        if ($this->geoEnabled) {
            $result = app(GeoBlockingService::class)->sync($rule, force: false);

            // Whitelist mode with a failed sync means the allow-set is not
            // fully populated — roll back instead of shrinking the perimeter.
            if ($result['status'] !== 'success' && $rule->mode === 'whitelist') {
                $this->restoreGeoSnapshot($rule, $previousMode, $previousCountries, $previousInterval);

                $this->notifyError((string) __('admin.security_geo_sync_failed', ['error' => $result['error'] ?? 'Unknown error']));
                $this->loadGeoState();

                return;
            }
        }

        if (! $this->regenerateAndApplyFirewall()) {
            // The kernel refused the change: restore the snapshot AND
            // re-promote the previous sets, because the sync above may
            // already have swapped the compiled kernel sets for the new
            // configuration while the running chain is still the old one.
            $this->restoreGeoSnapshot($rule, $previousMode, $previousCountries, $previousInterval);

            return;
        }

        $syncDetail = isset($result) ? ", sync: {$result['status']}" : '';

        SecurityAuditLog::record(
            action: 'geo_blocking_updated',
            ipAddress: $this->adminIp,
            description: "Geo-blocking settings saved (mode: {$rule->mode}{$syncDetail}).",
            adminId: Auth::guard('admin')->id(),
        );

        $this->loadGeoState();

        if ($this->geoEnabled && ($result['status'] ?? 'success') !== 'success') {
            $this->notifyError((string) __('admin.security_geo_sync_failed', ['error' => $result['error'] ?? 'Unknown error']));
        } else {
            $this->notifySuccess((string) __('admin.security_geo_saved'));
        }
    }

    /**
     * Add or remove a country code from the list.
     */
    public function toggleGeoCountry(string $countryCode): void
    {
        $this->ensureGeoManagePermission();

        $cc = strtoupper(trim($countryCode));
        if (in_array($cc, $this->geoCountries, true)) {
            $this->geoCountries = array_values(array_diff($this->geoCountries, [$cc]));
        } else {
            $this->geoCountries[] = $cc;
        }

        $this->saveGeoSettings();
    }

    /**
     * Restore a snapshotted Geo-Blocking configuration after a refused or
     * failed change, then re-promote its kernel sets so the database and
     * the running perimeter never disagree.
     *
     * @param  array<int, string>|null  $countries
     */
    private function restoreGeoSnapshot(SecurityGeoRule $rule, ?string $mode, ?array $countries, ?string $interval): void
    {
        $rule->mode = $mode ?? 'blacklist';
        $rule->countries = $countries ?? [];
        $rule->sync_interval = $interval ?? 'weekly';
        $rule->save();

        // Re-promote the previous sets from the zone cache: the attempted
        // change may already have swapped the compiled kernel sets while
        // the running chain still matches the snapshot above.
        app(GeoBlockingService::class)->sync($rule);
        $this->loadGeoState();
    }

    /**
     * Open confirmation modal for clearing all geo rules.
     */
    public function confirmClearGeoRules(): void
    {
        $this->confirmingClearGeoRules = true;
    }

    /**
     * Cancel pending clearing of geo rules.
     */
    public function cancelClearGeoRules(): void
    {
        $this->confirmingClearGeoRules = false;
    }

    /**
     * Clear all geo rules and kernel elements.
     */
    public function clearGeoRules(): void
    {
        $this->confirmingClearGeoRules = false;
        $this->ensureGeoManagePermission();

        $rule = SecurityGeoRule::instance();

        // Clearing an armed whitelist rule would empty its allow-set while
        // the running chain still drops everything not in it; the perimeter
        // must be disabled (or switched to blacklist) first.
        if ($rule->enabled && $rule->mode === 'whitelist') {
            $this->notifyError((string) __('admin.security_geo_whitelist_countries_required'));

            return;
        }
        $rule->countries = [];
        $rule->v4_cidrs_count = 0;
        $rule->v6_cidrs_count = 0;
        $rule->save();

        app(GeoBlockingService::class)->clear();
        $this->regenerateAndApplyFirewall();

        $this->loadGeoState();
        $this->notifySuccess((string) __('admin.security_geo_cleared'));
    }

    /**
     * Return packet drop counter for Geo-Blocking from nftables.
     */
    public function geoDropCounter(): ?int
    {
        $status = app(SecurityExecutorInterface::class)->status();

        if ($status === '') {
            return null;
        }

        // Anchor each set name to the counter on its own rule line: chain
        // rules reference sets as @set_name and carry the counter on that
        // exact line. Matching across lines could pair a geo set definition
        // with an unrelated rule's counter further down the dump.
        if (preg_match('/@(?:geo_blocked_ips|geo_allowed_ips)(?:6)?\s+counter\s+packets\s+(\d+)/', $status, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Helper to regenerate the pending firewall ruleset and apply it safely via the bounded helper.
     *
     * Geo actions accept the granular geo-blocking permission OR the blanket
     * security.edit permission so either can authorize the scoped apply.
     */
    protected function regenerateAndApplyFirewall(): bool
    {
        return $this->autoApplyFirewallRuleset(
            acceptedPermissions: ['security.geo-blocking.manage', 'security.edit']
        );
    }

    /**
     * Complete ISO 3166-1 alpha-2 countries catalog for the UI picker.
     *
     * @return array<string, string> Code => Name
     */
    public function availableCountries(): array
    {
        return IsoCountries::all();
    }

    /**
     * Ensure current administrator has geo-blocking management permissions.
     */
    private function ensureGeoManagePermission(): void
    {
        $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        abort_unless(
            $actor !== null && ($actor->hasPermission('security.geo-blocking.manage') || $actor->hasPermission('security.edit')),
            403
        );
    }
}
