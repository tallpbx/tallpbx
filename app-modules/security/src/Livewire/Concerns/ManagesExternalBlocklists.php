<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Services\ThreatFeedIngestionService;
use Modules\Security\Services\ThreatFeedManager;

/**
 * Livewire component concern for managing external threat feed blocklists.
 */
trait ManagesExternalBlocklists
{
    public bool $feedEnabled = true;

    public string $feedCountryMode = 'all';

    public string $feedCountriesInput = '';

    public string $feedSyncInterval = 'hourly';

    /**
     * Load the external blocklist (threat feed) configuration into the form properties.
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
     * Persist the external blocklist configuration from the tab form.
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
            description: "External blocklist '{$feed->name}' settings updated (mode: {$feed->country_mode}, interval: {$feed->sync_interval}, ".($feed->enabled ? 'enabled' : 'disabled').').',
            details: ['provider' => $feed->provider, 'countries' => $countries],
            adminId: Auth::guard('admin')->id(),
        );

        $this->notifySuccess((string) __('admin.security_threat_feed_saved'));
    }

    /**
     * Run an immediate forced sync of the external blocklist and report the result.
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
     * Alias for syncThreatFeedNow using the modernized terminology.
     */
    public function syncExternalBlocklistsNow(): void
    {
        $this->syncThreatFeedNow();
    }

    /**
     * Flush the feed's kernel elements without disabling the feed.
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
            description: 'All external blocklist kernel elements removed from the Security Center; the feed configuration was left enabled.',
            adminId: Auth::guard('admin')->id(),
        );

        $this->notifySuccess((string) __('admin.security_threat_feed_blocks_removed'));
    }

    /**
     * Read the kernel drop counter for external blocklists from nftables.
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
}
