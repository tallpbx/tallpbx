<?php

declare(strict_types=1);

namespace Modules\Security\Support;

/**
 * Immutable outcome of one threat feed sync attempt.
 *
 * Carries exactly what the provider needs to persist on the feed row and
 * what the panel shows: the status badge (success, not_modified, failed),
 * the compiled entry count, how many lines were rejected by validation,
 * the cache validators for the next conditional request, and a
 * plain-language error when the sync failed.
 */
final readonly class ThreatFeedSyncResult
{
    /**
     * @param  'success'|'not_modified'|'failed'  $status
     */
    private function __construct(
        public string $status,
        public int $entriesCount,
        public int $rejectedLines,
        public ?string $etag,
        public ?string $lastModified,
        public ?string $error,
    ) {}

    /**
     * A download that parsed, validated, and compiled successfully.
     */
    public static function success(int $entriesCount, int $rejectedLines, ?string $etag, ?string $lastModified): self
    {
        return new self('success', $entriesCount, $rejectedLines, $etag, $lastModified, null);
    }

    /**
     * The server confirmed the cached copy is still current (HTTP 304).
     */
    public static function notModified(): self
    {
        return new self('not_modified', 0, 0, null, null, null);
    }

    /**
     * The sync aborted and the previously loaded list stays in place.
     */
    public static function failed(string $error): self
    {
        return new self('failed', 0, 0, null, null, $error);
    }
}
