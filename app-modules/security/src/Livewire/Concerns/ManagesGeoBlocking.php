<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Services\GeoBlockingService;
use Modules\Security\Services\LockoutGuardService;

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
        }

        $rule->enabled = $enabled;
        $rule->save();
        $this->geoEnabled = $enabled;

        // Regenerate main ruleset so the geo stage in input chain is emitted or omitted
        if (! $this->regenerateAndApplyFirewall()) {
            $rule->enabled = ! $enabled;
            $rule->save();
            $this->geoEnabled = ! $enabled;

            return;
        }

        if (! $enabled) {
            app(GeoBlockingService::class)->clear();

            SecurityAuditLog::record(
                action: 'geo_blocking_disabled',
                ipAddress: $this->adminIp,
                description: 'Geo-Blocking was disabled from the Security Center; kernel geo sets were cleared.',
                adminId: Auth::guard('admin')->id(),
            );

            $this->notifySuccess((string) __('admin.security_geo_disabled_notice'));
        } else {
            $result = app(GeoBlockingService::class)->sync($rule, force: true);

            SecurityAuditLog::record(
                action: 'geo_blocking_enabled',
                ipAddress: $this->adminIp,
                description: "Geo-Blocking was enabled (mode: {$rule->mode}, countries: ".count($rule->countries ?? []).').',
                adminId: Auth::guard('admin')->id(),
            );

            if ($result['status'] === 'success') {
                $this->notifySuccess((string) __('admin.security_geo_enabled_notice'));
            } else {
                $this->notifyError((string) __('admin.security_geo_sync_failed', ['error' => $result['error'] ?? 'Unknown error']));
            }
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
        }

        $rule = SecurityGeoRule::instance();
        $rule->mode = $this->geoMode;
        $rule->sync_interval = $this->geoSyncInterval;
        $rule->countries = array_values(array_unique(array_map('strtoupper', $this->geoCountries)));
        $rule->save();

        if (! $this->regenerateAndApplyFirewall()) {
            return;
        }

        if ($this->geoEnabled) {
            app(GeoBlockingService::class)->sync($rule, force: false);
        }

        SecurityAuditLog::record(
            action: 'geo_blocking_updated',
            ipAddress: $this->adminIp,
            description: "Geo-blocking settings saved (mode: {$rule->mode}).",
            adminId: Auth::guard('admin')->id(),
        );

        $this->loadGeoState();
        $this->notifySuccess((string) __('admin.security_geo_saved'));
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
     * Trigger an on-demand sync of Geo-IP zones.
     */
    public function syncGeoIpNow(): void
    {
        $this->ensureGeoManagePermission();

        $rule = SecurityGeoRule::instance();
        $result = app(GeoBlockingService::class)->sync($rule, force: true);

        if ($result['status'] === 'success') {
            $this->notifySuccess((string) __('admin.security_geo_sync_success', [
                'v4' => $result['v4_count'],
                'v6' => $result['v6_count'],
            ]));
        } else {
            $this->notifyError((string) __('admin.security_geo_sync_failed', [
                'error' => $result['error'] ?? 'Unknown error',
            ]));
        }

        $this->loadGeoState();
    }

    /**
     * Clear all geo rules and kernel elements.
     */
    public function clearGeoRules(): void
    {
        $this->ensureGeoManagePermission();

        $rule = SecurityGeoRule::instance();
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

        if (preg_match('/(?:geo_blocked_ips|geo_allowed_ips).*?counter packets (\d+)/s', $status, $matches) === 1) {
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
     * Common ISO 3166-1 alpha-2 countries catalog for the UI picker.
     *
     * @return array<string, string> Code => Name
     */
    public function availableCountries(): array
    {
        return [
            'US' => 'United States',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'NL' => 'Netherlands',
            'CN' => 'China',
            'RU' => 'Russian Federation',
            'UA' => 'Ukraine',
            'BR' => 'Brazil',
            'IN' => 'India',
            'SG' => 'Singapore',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'IL' => 'Israel',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'MX' => 'Mexico',
            'ZA' => 'South Africa',
            'TR' => 'Turkey',
            'PL' => 'Poland',
            'RO' => 'Romania',
            'SE' => 'Sweden',
            'CH' => 'Switzerland',
            'IE' => 'Ireland',
            'HK' => 'Hong Kong',
            'TW' => 'Taiwan',
            'VN' => 'Vietnam',
            'ID' => 'Indonesia',
            'NG' => 'Nigeria',
            'EG' => 'Egypt',
            'PK' => 'Pakistan',
            'BD' => 'Bangladesh',
            'PH' => 'Philippines',
            'AR' => 'Argentina',
            'CO' => 'Colombia',
            'CL' => 'Chile',
            'PE' => 'Peru',
            'NZ' => 'New Zealand',
            'AT' => 'Austria',
            'BE' => 'Belgium',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'NO' => 'Norway',
            'GR' => 'Greece',
            'PT' => 'Portugal',
            'CZ' => 'Czech Republic',
            'HU' => 'Hungary',
            'BG' => 'Bulgaria',
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
        ];
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
