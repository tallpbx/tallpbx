<?php

declare(strict_types=1);

namespace Modules\Security\Contracts;

use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Support\ThreatFeedSyncResult;

/**
 * Contract every threat intelligence provider driver implements.
 *
 * The interface is deliberately instance-oriented — it receives the feed
 * configuration row rather than a bare identifier — so a future release can
 * host several feeds per driver without touching any implementation.
 */
interface ThreatFeedProviderInterface
{
    /**
     * Stable driver identifier stored in the feed's `provider` column.
     */
    public function identifier(): string;

    /**
     * Human-readable provider name for the Security Center.
     */
    public function name(): string;

    /**
     * Build the download URL for this feed's current country filtering.
     */
    public function fetchUrl(SecurityThreatFeed $feed): string;

    /**
     * Run one full sync: download, validate, compile, and persist metadata.
     *
     * @param  bool  $force  Bypass the HTTP conditional cache, never the
     *                       fail-open checks — a forced sync of a broken
     *                       feed must still keep the last good list.
     */
    public function sync(SecurityThreatFeed $feed, bool $force = false): ThreatFeedSyncResult;
}
