<?php

declare(strict_types=1);

namespace Modules\Security\Support;

/**
 * Immutable value object representing the synchronization state of the Linux firewall.
 *
 * Encapsulates comparison results between desired database configuration,
 * authoritative sidecar provenance, and live Linux kernel packet filtering state.
 */
final class FirewallSyncStatus
{
    /**
     * Create a new firewall sync status instance.
     *
     * @param  string  $state  'in_sync', 'drift', or 'unknown'
     * @param  string|null  $desiredDigest  SHA-256 canonical digest of database configuration
     * @param  string|null  $appliedDigest  SHA-256 digest recorded in the applied sidecar
     * @param  string|null  $appliedPolicy  Chain policy recorded in the applied sidecar ('drop'|'accept')
     * @param  string|null  $appliedAt  UTC ISO-8601 timestamp when ruleset was loaded
     * @param  array<int, string>  $issues  List of human-readable sync discrepancy issues
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $desiredDigest = null,
        public readonly ?string $appliedDigest = null,
        public readonly ?string $appliedPolicy = null,
        public readonly ?string $appliedAt = null,
        public readonly array $issues = [],
    ) {}

    /**
     * Factory for an in-sync state.
     */
    public static function inSync(
        ?string $desiredDigest = null,
        ?string $appliedDigest = null,
        ?string $appliedPolicy = null,
        ?string $appliedAt = null,
    ): self {
        return new self(
            state: 'in_sync',
            desiredDigest: $desiredDigest,
            appliedDigest: $appliedDigest,
            appliedPolicy: $appliedPolicy,
            appliedAt: $appliedAt,
            issues: [],
        );
    }

    /**
     * Factory for a drift state.
     *
     * @param  array<int, string>  $issues  Detected discrepancy issues
     */
    public static function drift(
        array $issues,
        ?string $desiredDigest = null,
        ?string $appliedDigest = null,
        ?string $appliedPolicy = null,
        ?string $appliedAt = null,
    ): self {
        return new self(
            state: 'drift',
            desiredDigest: $desiredDigest,
            appliedDigest: $appliedDigest,
            appliedPolicy: $appliedPolicy,
            appliedAt: $appliedAt,
            issues: $issues,
        );
    }

    /**
     * Factory for an unknown/unverified state.
     *
     * @param  array<int, string>  $issues  Reasons why sync state could not be evaluated
     */
    public static function unknown(
        array $issues = [],
        ?string $desiredDigest = null,
        ?string $appliedDigest = null,
        ?string $appliedPolicy = null,
        ?string $appliedAt = null,
    ): self {
        return new self(
            state: 'unknown',
            desiredDigest: $desiredDigest,
            appliedDigest: $appliedDigest,
            appliedPolicy: $appliedPolicy,
            appliedAt: $appliedAt,
            issues: $issues,
        );
    }

    /**
     * Whether the active firewall is verified to be in sync.
     */
    public function isInSync(): bool
    {
        return $this->state === 'in_sync';
    }

    /**
     * Whether configuration or runtime drift was detected.
     */
    public function isDrift(): bool
    {
        return $this->state === 'drift';
    }

    /**
     * Whether the firewall status could not be authoritatively verified.
     */
    public function isUnknown(): bool
    {
        return $this->state === 'unknown';
    }

    /**
     * Convert the status to an associative array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'desired_digest' => $this->desiredDigest,
            'applied_digest' => $this->appliedDigest,
            'applied_policy' => $this->appliedPolicy,
            'applied_at' => $this->appliedAt,
            'issues' => $this->issues,
        ];
    }
}
