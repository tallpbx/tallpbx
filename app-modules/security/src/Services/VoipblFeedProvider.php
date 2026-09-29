<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Modules\Security\Contracts\ThreatFeedProviderInterface;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Support\ThreatFeedSyncResult;

/**
 * VoIPBL (voipbl.org) provider driver.
 *
 * Publishes a community-maintained list of hostile VoIP subnets (IPv4
 * ranges only) with optional country filtering: `bc` isolates traffic from
 * the listed countries, `wc` excludes the home countries.
 */
class VoipblFeedProvider implements ThreatFeedProviderInterface
{
    /**
     * Create the provider instance.
     *
     * @param  ThreatFeedIngestionService  $ingestion  Shared download/compile pipeline
     */
    public function __construct(
        private readonly ThreatFeedIngestionService $ingestion,
    ) {}

    /**
     * Stable driver identifier stored in the feed's provider column.
     */
    public function identifier(): string
    {
        return 'voipbl';
    }

    /**
     * Human-readable provider name for the Security Center.
     */
    public function name(): string
    {
        return 'VoIPBL';
    }

    /**
     * Build the download URL for this feed's country mode.
     *
     * Country codes are normalized to uppercase ISO 3166-1 alpha-2 so the
     * query string is deterministic regardless of how the panel stored them.
     */
    public function fetchUrl(SecurityThreatFeed $feed): string
    {
        $countries = array_values(array_filter(array_map(
            static fn (mixed $country): string => strtoupper(trim((string) $country)),
            $feed->countries ?? []
        )));

        return match ($feed->country_mode) {
            'blacklist' => 'https://www.voipbl.org/update/?bc='.implode(',', $countries),
            'whitelist' => 'https://www.voipbl.org/update/?wc='.implode(',', $countries),
            default => 'https://www.voipbl.org/update/',
        };
    }

    /**
     * Run one full sync and persist the outcome on the feed row.
     *
     * Metadata discipline: only a successful download replaces the entry
     * count, rejected count, and cache validators; the attempt timestamp
     * refreshes on every run so staleness means exactly one thing — no
     * sync attempts are happening at all.
     */
    public function sync(SecurityThreatFeed $feed, bool $force = false): ThreatFeedSyncResult
    {
        $result = $this->ingestion->ingest($feed, $this->fetchUrl($feed), $force);

        // last_sync_at is the last ATTEMPT timestamp: it refreshes on every
        // run (including failures) so the staleness alert means "no sync
        // attempts are happening at all" (a dead scheduler), never a feed
        // that fails loudly on schedule.
        $updates = [
            'last_sync_at' => now(),
            'last_status' => $result->status,
            'last_error' => $result->error,
        ];

        if ($result->status === 'success') {
            $updates['entries_count'] = $result->entriesCount;
            $updates['last_rejected_lines'] = $result->rejectedLines;
            $updates['etag'] = $result->etag ?? $feed->etag;
            $updates['last_modified_header'] = $result->lastModified ?? $feed->last_modified_header;
        }

        $feed->update($updates);

        return $result;
    }
}
