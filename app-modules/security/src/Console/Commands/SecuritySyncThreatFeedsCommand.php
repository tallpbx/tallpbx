<?php

declare(strict_types=1);

namespace Modules\Security\Console\Commands;

use Illuminate\Console\Command;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Services\ThreatFeedIngestionService;
use Modules\Security\Services\ThreatFeedManager;

/**
 * Sync due public threat feeds on the hourly schedule.
 *
 * The hourly tick is cheap for feeds configured with longer intervals: this
 * command honours each feed's own sync_interval, clears kernel elements the
 * moment a feed is disabled, and records staleness discovered after an
 * outage before attempting the catch-up sync.
 */
class SecuritySyncThreatFeedsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:sync-threat-feeds
        {--feed= : Sync only this provider identifier}
        {--force : Bypass the HTTP conditional cache (never the fail-open checks)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync due public threat feeds and keep the kernel sets current';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $feeds = SecurityThreatFeed::query()
            ->when(
                $this->option('feed'),
                fn ($query, $provider) => $query->where('provider', $provider)
            )
            ->get();

        if ($feeds->isEmpty()) {
            $this->warn('No threat feeds are configured.');

            return self::SUCCESS;
        }

        $manager = app(ThreatFeedManager::class);
        $ingestion = app(ThreatFeedIngestionService::class);
        $executor = app(SecurityExecutorInterface::class);
        $force = (bool) $this->option('force');

        foreach ($feeds as $feed) {
            if (! $feed->enabled) {
                $this->clearDisabledFeed($feed, $ingestion, $executor);

                continue;
            }

            // Staleness is checked BEFORE the attempt: it means the
            // scheduler missed at least two full intervals (silent rot),
            // which must be visible in the audit even though this run now
            // catches up successfully.
            if ($feed->isStale()) {
                SecurityAuditLog::record(
                    action: 'threat_feed_stale',
                    description: "Threat feed '{$feed->name}' went stale: no sync attempt for more than two intervals.",
                    details: [
                        'provider' => $feed->provider,
                        'last_sync_at' => $feed->last_sync_at?->toIso8601String(),
                    ],
                );
                $this->warn("{$feed->name}: stale — no sync attempt for more than two intervals.");
            }

            if (! $force && ! $this->isDue($feed)) {
                $this->line("{$feed->name}: not due yet, skipping.");

                continue;
            }

            $result = $manager->sync($feed, $force);

            match ($result->status) {
                'success' => $this->info("{$feed->name}: synced {$result->entriesCount} entries ({$result->rejectedLines} rejected)."),
                'not_modified' => $this->line("{$feed->name}: not modified since the last check."),
                default => $this->error("{$feed->name}: sync failed — {$result->error}"),
            };
        }

        return self::SUCCESS;
    }

    /**
     * Whether a feed's own interval has elapsed since its last attempt.
     */
    private function isDue(SecurityThreatFeed $feed): bool
    {
        // A feed left 'disabled' arms an immediate attempt the moment it is
        // re-enabled, before the shared sets are repopulated.
        if ($feed->last_status === 'disabled' || $feed->last_sync_at === null) {
            return true;
        }

        return $feed->last_sync_at->addSeconds($feed->syncIntervalSeconds())->lte(now());
    }

    /**
     * Flush the kernel sets on the disable transition and record it.
     *
     * A feed disabled in the panel keeps its last good file on disk, so the
     * transition itself must push an empty set-element file through the
     * helper — genuinely off means off in the kernel too.
     */
    private function clearDisabledFeed(
        SecurityThreatFeed $feed,
        ThreatFeedIngestionService $ingestion,
        SecurityExecutorInterface $executor,
    ): void {
        // Already recorded as disabled: nothing to do on this tick.
        if ($feed->last_status === 'disabled') {
            return;
        }

        $ingestion->clear();
        $executor->updateThreatFeed();

        $feed->update(['last_status' => 'disabled', 'last_error' => null]);

        SecurityAuditLog::record(
            action: 'threat_feed_disabled',
            description: "Threat feed '{$feed->name}' disabled: kernel elements cleared immediately.",
            details: ['provider' => $feed->provider],
        );

        $this->line("{$feed->name}: disabled — kernel elements cleared.");
    }
}
