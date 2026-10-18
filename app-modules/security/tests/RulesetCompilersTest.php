<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Services\Firewall\Compilers\BlocklistCompiler;
use Modules\Security\Services\Firewall\Compilers\GeoPerimeterCompiler;
use Modules\Security\Services\Firewall\Compilers\PreFilterCompiler;
use Modules\Security\Services\Firewall\Compilers\ServiceRulesCompiler;

it('BlocklistCompiler compiles expected permanent and dynamic sets', function (): void {
    SecurityIpList::create(['ip_address' => '198.51.100.1', 'type' => 'blacklist']);
    SecurityIpList::create(['ip_address' => '2001:db8::1', 'type' => 'blacklist']);
    SecurityIpList::create(['ip_address' => '192.0.2.1', 'type' => 'whitelist']);
    SecurityIpList::create(['ip_address' => '2001:db8::2', 'type' => 'whitelist']);

    $compiler = new BlocklistCompiler;
    $lines = $compiler->compileSets(
        blacklistEnabled: true,
        whitelistEnabled: true,
        attackProtectionEnabled: true
    );
    $output = implode("\n", $lines);

    expect($output)->toContain('set blacklist_ips {')
        ->and($output)->toContain('elements = { 198.51.100.1 }')
        ->and($output)->toContain('set blacklist_ips6 {')
        ->and($output)->toContain('elements = { 2001:db8::1 }')
        ->and($output)->toContain('set whitelist_ips {')
        ->and($output)->toContain('elements = { 192.0.2.1 }')
        ->and($output)->toContain('set whitelist_ips6 {')
        ->and($output)->toContain('elements = { 2001:db8::2 }')
        ->and($output)->toContain('set banned_ips {')
        ->and($output)->toContain('set banned_ips6 {')
        ->and($output)->toContain('set external_blocklist_ips {')
        ->and($output)->toContain('set external_blocklist_ips6 {')
        ->and($output)->toContain('set geo_blocked_ips {')
        ->and($output)->toContain('set geo_allowed_ips {');
});

it('BlocklistCompiler rejects invalid IP entries with informative error', function (): void {
    $compiler = new BlocklistCompiler;

    expect(fn () => $compiler->assertCompilableEntries(
        blacklistIps: ['invalid-ip'],
        whitelistIps: [],
        activeBans: collect()
    ))->toThrow(\RuntimeException::class, 'Cannot compile the firewall ruleset');
});

it('BlocklistCompiler generates observe and enforcing drop statements', function (): void {
    $compiler = new BlocklistCompiler;

    expect($compiler->dropStatement('test_stage', observeMode: true))->toContain('counter log prefix "tallpbx-observe:test_stage "')
        ->and($compiler->dropStatement('test_stage', observeMode: false, withCounter: true))->toBe('counter drop')
        ->and($compiler->dropStatement('test_stage', observeMode: false, withCounter: false))->toBe('drop');
});

it('GeoPerimeterCompiler compiles subchain and input jump stage for whitelist mode', function (): void {
    $geoRule = SecurityGeoRule::instance();
    $geoRule->update([
        'enabled' => true,
        'mode' => 'whitelist',
        'countries' => ['US', 'CA'],
    ]);

    $compiler = new GeoPerimeterCompiler;
    $subchain = implode("\n", $compiler->compileSubchain($geoRule, firewallEnabled: true, observeMode: false));
    $inputStage = implode("\n", $compiler->compileInputStage($geoRule));

    expect($subchain)->toContain('chain geo_perimeter {')
        ->and($subchain)->toContain('ip saddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 } return')
        ->and($subchain)->toContain('ip6 saddr { fc00::/7, fe80::/10 } return')
        ->and($subchain)->toContain('ip saddr != @geo_allowed_ips counter drop')
        ->and($subchain)->toContain('ip6 saddr != @geo_allowed_ips6 counter drop')
        ->and($inputStage)->toContain('# STAGE 9.5: GEO-BLOCKING')
        ->and($inputStage)->toContain('jump geo_perimeter');
});

it('GeoPerimeterCompiler compiles subchain for blacklist mode', function (): void {
    $geoRule = SecurityGeoRule::instance();
    $geoRule->update([
        'enabled' => true,
        'mode' => 'blacklist',
        'countries' => ['CN', 'RU'],
    ]);

    $compiler = new GeoPerimeterCompiler;
    $subchain = implode("\n", $compiler->compileSubchain($geoRule, firewallEnabled: true, observeMode: false));

    expect($subchain)->toContain('chain geo_perimeter {')
        ->and($subchain)->not->toContain('return')
        ->and($subchain)->toContain('ip saddr @geo_blocked_ips counter drop')
        ->and($subchain)->toContain('ip6 saddr @geo_blocked_ips6 counter drop');
});

it('PreFilterCompiler compiles stages in configured order', function (): void {
    $compiler = new PreFilterCompiler;
    $lines = $compiler->compileStages(observeMode: false);
    $output = implode("\n", $lines);

    expect($output)->toContain('# STAGE 1: BASE INVARIANT: UNCONDITIONAL LOOPBACK ACCESS')
        ->and($output)->toContain('iif "lo" accept')
        ->and($output)->toContain('# STAGE 2: ACCEPT WHITELISTED / TRUSTED IPs UNCONDITIONALLY')
        ->and($output)->toContain('ip saddr @whitelist_ips accept')
        ->and($output)->toContain('# STAGE 3: DROP INVALID PACKETS')
        ->and($output)->toContain('# STAGE 4: STATEFUL FAST PATH')
        ->and($output)->toContain('# STAGE 5: DROP BLACKLISTED NETWORKS')
        ->and($output)->toContain('# STAGE 6: DROP TEMPORARILY BANNED')
        ->and($output)->toContain('# STAGE 7: DROP AUTOMATED PUBLIC THREAT FEED');
});

it('PreFilterCompiler enforces lockout prevention constraints', function (): void {
    $compiler = new PreFilterCompiler;

    // Loopback must be first
    expect(fn () => $compiler->assertValidPreFilterOrder([
        'whitelist', 'loopback', 'invalid', 'fast_path', 'blacklist', 'banned', 'threat_feeds',
    ]))->toThrow(\RuntimeException::class, 'must start with the loopback stage');

    // Drop stages cannot be placed above whitelist
    expect(fn () => $compiler->assertValidPreFilterOrder([
        'loopback', 'blacklist', 'whitelist', 'invalid', 'fast_path', 'banned', 'threat_feeds',
    ]))->toThrow(\RuntimeException::class, 'must not place the blacklist stage above the whitelist');
});

it('PreFilterCompiler compiles ICMP and TFTP stages with IPv6 invariants', function (): void {
    $compiler = new PreFilterCompiler;

    $icmpOutput = implode("\n", $compiler->compileIcmpStage());
    expect($icmpOutput)->toContain('# STAGE 8: ICMP PING DIAGNOSTICS')
        ->and($icmpOutput)->toContain('icmp type echo-request')
        ->and($icmpOutput)->toContain('packet-too-big, mld-listener-query');

    $tftpOutput = implode("\n", $compiler->compileTftpStage(tftpDefenseEnabled: true, observeMode: false));
    expect($tftpOutput)->toContain('# STAGE 9: HARDENED TFTP DEFENSE PROFILE')
        ->and($tftpOutput)->toContain('udp dport 69 @th,64,16 0x0002 counter drop')
        ->and($tftpOutput)->toContain('update @tftp_flood4');
});

it('ServiceRulesCompiler compiles stages 10, 11, and 12 with port formatting', function (): void {
    $compiler = new ServiceRulesCompiler;

    expect($compiler->formatPortRange('80,443'))->toBe('{ 80, 443 }')
        ->and($compiler->formatPortRange('10000:20000'))->toBe('10000-20000')
        ->and($compiler->formatServiceNftablesRule('udp', '5060'))->toBe('udp dport 5060')
        ->and($compiler->formatServiceNftablesRule('both', '80,443'))->toBe('{ tcp, udp } dport { 80, 443 }')
        ->and($compiler->formatServiceNftablesRule('icmp'))->toBe('icmp type echo-request');

    $rules = $compiler->generateServiceRules('both', '{ 5060 }', 'accept', 'ip saddr 10.0.0.1 ');
    expect($rules)->toHaveCount(2)
        ->and($rules[0])->toBe('ip saddr 10.0.0.1 udp dport { 5060 } accept')
        ->and($rules[1])->toBe('ip saddr 10.0.0.1 tcp dport { 5060 } accept');

    $policyLines = $compiler->compileDefaultPolicy(observeMode: false, defaultPolicy: 'drop');
    expect(implode("\n", $policyLines))->toContain('# STAGE 12: DEFAULT INBOUND POLICY')
        ->and(implode("\n", $policyLines))->toContain('drop');
});
