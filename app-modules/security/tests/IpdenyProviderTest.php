<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Illuminate\Support\Facades\Http;
use Modules\Security\Services\Geo\IpdenyProvider;

it('fetches aggregated IPv4 and IPv6 country ranges using IpdenyProvider', function (): void {
    Http::fake([
        'https://www.ipdeny.com/ipblocks/data/aggregated/us-aggregated.zone' => Http::response("1.0.0.0/24\n1.0.4.0/22\n# comment\ninvalid-ip\n", 200),
        'https://www.ipdeny.com/ipv6/ipaddresses/aggregated/us-aggregated.zone' => Http::response("2600::/12\n2800::/12\n", 200),
    ]);

    $provider = new IpdenyProvider;
    $ranges = $provider->fetchCountryRanges('US');

    expect($ranges['v4'])->toBe(['1.0.0.0/24', '1.0.4.0/22'])
        ->and($ranges['v6'])->toBe(['2600::/12', '2800::/12'])
        ->and($provider->identifier())->toBe('ipdeny')
        ->and($provider->name())->toBe('IPdeny Aggregated Zones');
});

it('falls back to the GitHub RIR mirror if IPdeny primary download fails', function (): void {
    Http::fake([
        'https://www.ipdeny.com/ipblocks/data/aggregated/ca-aggregated.zone' => Http::response(null, 500),
        'https://raw.githubusercontent.com/herrbischoff/country-ip-blocks/master/ipv4/ca.cidr' => Http::response("24.48.0.0/14\n", 200),
        'https://www.ipdeny.com/ipv6/ipaddresses/aggregated/ca-aggregated.zone' => Http::response(null, 500),
        'https://raw.githubusercontent.com/herrbischoff/country-ip-blocks/master/ipv6/ca.cidr' => Http::response("2607:f8b0::/32\n", 200),
    ]);

    $provider = new IpdenyProvider;
    $ranges = $provider->fetchCountryRanges('CA');

    expect($ranges['v4'])->toBe(['24.48.0.0/14'])
        ->and($ranges['v6'])->toBe(['2607:f8b0::/32']);
});

it('returns empty ranges for invalid country codes', function (): void {
    $provider = new IpdenyProvider;

    expect($provider->fetchCountryRanges('USA')['v4'])->toBeEmpty()
        ->and($provider->fetchCountryRanges('12')['v4'])->toBeEmpty()
        ->and($provider->fetchCountryRanges('')['v4'])->toBeEmpty();
});
