<?php

declare(strict_types=1);

namespace Modules\Security\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Services\ThreatFeeds\ThreatFeedIngestionService;
use Modules\Security\Services\ThreatFeeds\ThreatFeedManager;

/**
 * Livewire component concern for managing external threat feed blocklists.
 */
trait ManagesExternalBlocklists
{
    public bool $feedEnabled = true;

    public string $feedSyncInterval = 'hourly';

    public bool $confirmingRemoveAllFeedBlocks = false;

    /**
     * Load the external blocklist (threat feed) configuration into the form properties.
     */
    public function loadFeedState(): void
    {
        $feed = SecurityThreatFeed::where('provider', 'voipbl')->first();

        $this->feedEnabled = $feed?->enabled ?? false;
        $this->feedSyncInterval = $feed?->sync_interval ?? 'daily';
    }

    /**
     * Turn the external threat feed blocklist on or off immediately.
     */
    public function setFeedEnabled(bool $enabled): void
    {
        $this->ensureFeedManagePermission();

        $feed = $this->voipblFeed();
        $feed->update(['enabled' => $enabled]);
        $this->feedEnabled = $enabled;

        if (! $enabled) {
            app(ThreatFeedIngestionService::class)->clear();

            if (! app(SecurityExecutorInterface::class)->updateExternalBlocklist()) {
                $this->notifyError((string) __('admin.security_threat_feed_remove_blocks_failed'));

                return;
            }

            SecurityAuditLog::record(
                action: 'threat_feed_disabled',
                ipAddress: $this->adminIp,
                description: "External blocklist '{$feed->name}' disabled from the Security Center; kernel blocklist set was flushed.",
                details: ['provider' => $feed->provider],
                adminId: Auth::guard('admin')->id(),
            );

            $this->notifySuccess((string) __('admin.security_threat_feed_disabled_notice'));
        } else {
            $result = app(ThreatFeedManager::class)->sync($feed, force: true);

            SecurityAuditLog::record(
                action: 'threat_feed_enabled',
                ipAddress: $this->adminIp,
                description: "External blocklist '{$feed->name}' enabled from the Security Center.",
                details: ['provider' => $feed->provider],
                adminId: Auth::guard('admin')->id(),
            );

            if ($result->status === 'success' || $result->status === 'not_modified') {
                $this->notifySuccess((string) __('admin.security_threat_feed_enabled_notice'));
            } else {
                $this->notifyError((string) __('admin.security_threat_feed_sync_failed', ['error' => (string) $result->error]));
            }
        }

        $this->loadFeedState();
    }

    /**
     * Persist the external blocklist configuration from the tab form.
     */
    public function saveFeedSettings(): void
    {
        $this->ensureFeedManagePermission();

        $this->validate([
            'feedSyncInterval' => ['required', 'in:hourly,4_hours,12_hours,daily'],
        ]);

        $feed = $this->voipblFeed();
        $feed->update([
            'enabled' => $this->feedEnabled,
            'sync_interval' => $this->feedSyncInterval,
        ]);

        SecurityAuditLog::record(
            action: 'threat_feed_updated',
            ipAddress: $this->adminIp,
            description: "External blocklist '{$feed->name}' settings updated (interval: {$feed->sync_interval}, ".($feed->enabled ? 'enabled' : 'disabled').').',
            details: ['provider' => $feed->provider],
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
     * Open confirmation modal for removing all feed blocks.
     */
    public function confirmRemoveAllFeedBlocks(): void
    {
        $this->confirmingRemoveAllFeedBlocks = true;
    }

    /**
     * Cancel pending removal of all feed blocks.
     */
    public function cancelRemoveAllFeedBlocks(): void
    {
        $this->confirmingRemoveAllFeedBlocks = false;
    }

    /**
     * Flush the feed's kernel elements without disabling the feed.
     */
    public function removeAllFeedBlocks(): void
    {
        $this->confirmingRemoveAllFeedBlocks = false;
        $this->ensureFeedManagePermission();

        app(ThreatFeedIngestionService::class)->clear();

        if (! app(SecurityExecutorInterface::class)->updateExternalBlocklist()) {
            $this->notifyError((string) __('admin.security_threat_feed_remove_blocks_failed'));

            return;
        }

        $feed = $this->voipblFeed();
        $feed->update(['entries_count' => 0]);

        SecurityAuditLog::record(
            action: 'threat_feed_blocks_removed',
            ipAddress: $this->adminIp,
            description: 'All external blocklist kernel elements removed from the Security Center; the feed configuration was left enabled.',
            adminId: Auth::guard('admin')->id(),
        );

        $this->loadFeedState();

        $this->notifySuccess((string) __('admin.security_threat_feed_blocks_removed'));
    }

    /**
     * Read the kernel drop counter for external blocklists from nftables.
     */
    public function feedDropCounter(): ?int
    {
        $status = app(SecurityExecutorInterface::class)->status();

        if ($status === '' || preg_match('/ip saddr @(?:external_blocklist_ips|threat_feed_ips) counter packets (\d+)/', $status, $matches) !== 1) {
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
     * Refuse feed mutations without the dedicated manage permission.
     */
    private function ensureFeedManagePermission(): void
    {
        $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();

        abort_unless($actor !== null && $actor->hasPermission('security.threat-feeds.manage'), 403);
    }
}
