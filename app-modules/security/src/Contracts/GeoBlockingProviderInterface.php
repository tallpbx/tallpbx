<?php

declare(strict_types=1);

namespace Modules\Security\Contracts;

/**
 * Contract for Geo-Blocking country IP allocation providers.
 */
interface GeoBlockingProviderInterface
{
    /**
     * Provider driver identifier (e.g. 'ipdeny').
     */
    public function identifier(): string;

    /**
     * Display name for the provider.
     */
    public function name(): string;

    /**
     * Fetch the aggregated CIDRs for a given ISO 3166-1 alpha-2 country code.
     *
     * @param  string  $countryCode  Two-letter uppercase country code (e.g., 'US', 'DE')
     * @return array{v4: array<int, string>, v6: array<int, string>}
     */
    public function fetchCountryRanges(string $countryCode): array;
}
