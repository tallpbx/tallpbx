<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Services\Firewall\SecurityConfigGenerator;

it('generates geo set declarations in nftables ruleset', function (): void {
    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    expect($nft)->toContain('set geo_blocked_ips {')
        ->and($nft)->toContain('set geo_blocked_ips6 {')
        ->and($nft)->toContain('set geo_allowed_ips {')
        ->and($nft)->toContain('set geo_allowed_ips6 {');
});

it('emits blacklist geo drop rules for all traffic when enabled', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update([
        'enabled' => true,
        'mode' => 'blacklist',
        'countries' => ['CN', 'RU'],
    ]);

    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    expect($nft)->toContain('# STAGE 9.5: GEO-BLOCKING')
        ->and($nft)->toContain('jump geo_perimeter')
        ->and($nft)->toContain('chain geo_perimeter {')
        ->and($nft)->toContain('ip saddr @geo_blocked_ips counter drop')
        ->and($nft)->toContain('ip6 saddr @geo_blocked_ips6 counter drop')
        ->and($nft)->not->toContain('ip saddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 } accept');
});

it('emits all-traffic whitelist geo drop rules when in geofence mode', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update([
        'enabled' => true,
        'mode' => 'whitelist',
        'countries' => ['US', 'CA'],
    ]);

    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    expect($nft)->toContain('# STAGE 9.5: GEO-BLOCKING')
        ->and($nft)->toContain('jump geo_perimeter')
        ->and($nft)->toContain('chain geo_perimeter {')
        ->and($nft)->toContain('ip saddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 } return')
        ->and($nft)->toContain('ip saddr != @geo_allowed_ips counter drop')
        ->and($nft)->toContain('ip6 saddr != @geo_allowed_ips6 counter drop')
        ->and($nft)->not->toContain('ip saddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 } accept');
});
