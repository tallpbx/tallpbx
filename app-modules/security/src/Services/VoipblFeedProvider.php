<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Contracts\ThreatFeedProviderInterface;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Support\ThreatFeedSyncResult;

/**
 * VoIPBL (voipbl.org) provider driver.
 *
 * Publishes a community-maintained list of hostile VoIP subnets (IPv4
 * ranges only) to drop known scanners and fraud botnets at the perimeter.
 */
class VoipblFeedProvider implements ThreatFeedProviderInterface
{
    public const FEED_URL = 'https://www.voipbl.org/update/';

    public const SEARCH_URL = 'https://www.voipbl.org/check/';

    /**
     * Create the provider instance.
     *
     * @param  ThreatFeedIngestionService  $ingestion  Shared download/compile pipeline
     * @param  SecurityExecutorInterface  $executor  Bounded helper executor (loads compiled elements)
     */
    public function __construct(
        private readonly ThreatFeedIngestionService $ingestion,
        private readonly SecurityExecutorInterface $executor,
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
     * Build the download URL for this feed.
     */
    public function fetchUrl(SecurityThreatFeed $feed): string
    {
        return self::FEED_URL;
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

            // Push the freshly compiled elements into the kernel through the
            // bounded helper. A refused load (stale helper, missing pending
            // file) must surface as a failed sync: reporting a healthy feed
            // while the kernel blocks nothing would mislead the operator.
            if (! $this->executor->updateExternalBlocklist()) {
                $updates['last_status'] = 'failed';
                $updates['last_error'] = 'The external blocklist was downloaded but could not be loaded into the kernel (the security helper is missing or out of date).';
            }
        }

        $feed->update($updates);

        return $result;
    }
}
