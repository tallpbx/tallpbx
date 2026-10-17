<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Security\Contracts\GeoBlockingProviderInterface;
use Modules\Security\Support\AddressFamily;

/**
 * IPdeny Aggregated Zone Provider for Geo-Blocking.
 *
 * Downloads pre-aggregated IPv4 and IPv6 zone files per country code.
 * Falls back to the community RIR GitHub mirror if ipdeny.com is unreachable.
 */
class IpdenyProvider implements GeoBlockingProviderInterface
{
    public const IPDENY_V4_TEMPLATE = 'https://www.ipdeny.com/ipblocks/data/aggregated/%s-aggregated.zone';

    public const IPDENY_V6_TEMPLATE = 'https://www.ipdeny.com/ipv6/ipaddresses/aggregated/%s-aggregated.zone';

    public const FALLBACK_V4_TEMPLATE = 'https://raw.githubusercontent.com/herrbischoff/country-ip-blocks/master/ipv4/%s.cidr';

    public const FALLBACK_V6_TEMPLATE = 'https://raw.githubusercontent.com/herrbischoff/country-ip-blocks/master/ipv6/%s.cidr';

    /**
     * Provider driver identifier.
     */
    public function identifier(): string
    {
        return 'ipdeny';
    }

    /**
     * Provider display name.
     */
    public function name(): string
    {
        return 'IPdeny Aggregated Zones';
    }

    /**
     * Fetch aggregated IPv4 and IPv6 CIDRs for the given country code.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code (e.g., 'US')
     * @return array{v4: array<int, string>, v6: array<int, string>}
     */
    public function fetchCountryRanges(string $countryCode): array
    {
        $cc = strtolower(trim($countryCode));
        if (strlen($cc) !== 2 || ! ctype_alpha($cc)) {
            return ['v4' => [], 'v6' => []];
        }

        $v4 = $this->fetchZone(
            sprintf(self::IPDENY_V4_TEMPLATE, $cc),
            sprintf(self::FALLBACK_V4_TEMPLATE, $cc),
            'ipv4'
        );

        $v6 = $this->fetchZone(
            sprintf(self::IPDENY_V6_TEMPLATE, $cc),
            sprintf(self::FALLBACK_V6_TEMPLATE, $cc),
            'ipv6'
        );

        return [
            'v4' => $v4,
            'v6' => $v6,
        ];
    }

    /**
     * Download and parse a zone file with automatic fallback.
     *
     * @return array<int, string> Validated address/CIDR strings
     */
    protected function fetchZone(string $primaryUrl, string $fallbackUrl, string $expectedFamily): array
    {
        $body = $this->downloadWithFallback($primaryUrl, $fallbackUrl);
        if ($body === null || trim($body) === '') {
            return [];
        }

        $ranges = [];
        $lines = explode("\n", $body);
        foreach ($lines as $line) {
            $entry = trim($line);
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            if (AddressFamily::classify($entry) === $expectedFamily) {
                $ranges[] = $entry;
            }
        }

        return array_values(array_unique($ranges));
    }

    /**
     * Attempt download from primary URL; if failed or timed out, attempt fallback.
     */
    protected function downloadWithFallback(string $primaryUrl, string $fallbackUrl): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'TallPBX-GeoIP/1.0',
                    'Accept-Encoding' => 'gzip',
                ])
                ->get($primaryUrl);

            if ($response->successful()) {
                return $response->body();
            }

            Log::warning("IPdeny download failed with HTTP {$response->status()} for {$primaryUrl}; trying fallback.");
        } catch (\Throwable $e) {
            Log::warning("IPdeny download exception for {$primaryUrl}: {$e->getMessage()}; trying fallback.");
        }

        try {
            $fallbackResponse = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'TallPBX-GeoIP/1.0',
                    'Accept-Encoding' => 'gzip',
                ])
                ->get($fallbackUrl);

            if ($fallbackResponse->successful()) {
                return $fallbackResponse->body();
            }

            Log::warning("GeoIP fallback download failed with HTTP {$fallbackResponse->status()} for {$fallbackUrl}.");
        } catch (\Throwable $e) {
            Log::error("GeoIP fallback download exception for {$fallbackUrl}: {$e->getMessage()}.");
        }

        return null;
    }
}
