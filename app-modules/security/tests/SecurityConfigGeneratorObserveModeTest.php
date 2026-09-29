<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for the global observe mode.
 *
 * Observe mode is a generation-time transform: every drop verdict becomes a
 * rate-limited counter + log with no verdict, the default inbound policy is
 * forced to accept, and accept rules stay byte-identical to the enforcing
 * build.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

it('converts every drop rule into a rate-limited counter log without verdicts', function (): void {
    SecurityRule::create([
        'sequence' => 7,
        'description' => 'Observed custom drop',
        'source_ip' => '192.0.2.0/24',
        'custom_port' => '3000',
        'custom_protocol' => 'udp',
        'action' => 'drop',
        'enabled' => true,
    ]);

    SecuritySetting::set('firewall_observe_mode', true);

    $nft = (new SecurityConfigGenerator)->generate();

    // Accepts are untouched: observing must not change what is admitted.
    expect($nft)->toContain('iif "lo" accept')
        ->and($nft)->toContain('ip saddr @whitelist_ips accept')
        ->and($nft)->toContain('ct state established,related accept')
        ->and($nft)->toContain('icmp type echo-request limit rate 5/second burst 5 packets accept');

    // Built-in drops become counter + rate-limited log with no verdict.
    expect($nft)->not->toContain('ct state invalid drop')
        ->and($nft)->toContain('ct state invalid counter log prefix "tallpbx-observe:invalid " limit rate 100/minute')
        ->and($nft)->not->toContain('ip saddr @blacklist_ips drop')
        ->and($nft)->toContain('ip saddr @blacklist_ips counter log prefix "tallpbx-observe:blacklist " limit rate 100/minute')
        ->and($nft)->toContain('ip6 saddr @blacklist_ips6 counter log prefix "tallpbx-observe:blacklist " limit rate 100/minute')
        ->and($nft)->toContain('ip saddr @banned_ips counter log prefix "tallpbx-observe:bans " limit rate 100/minute')
        ->and($nft)->toContain('ip saddr @threat_feed_ips counter log prefix "tallpbx-observe:threat_feeds " limit rate 100/minute');

    // Custom-rule drops are observed too — nothing is dropped while observing.
    expect($nft)->not->toContain('udp dport 3000 drop')
        ->and($nft)->toContain('ip saddr 192.0.2.0/24 udp dport 3000 counter log prefix "tallpbx-observe:custom " limit rate 100/minute');
});

it('forces the chain policy to accept and validates against the real nft parser', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    $tempDir = sys_get_temp_dir().'/tallpbx_observe_mode_test_'.uniqid();
    $generator = new SecurityConfigGenerator($tempDir);
    $nft = $generator->generate();

    // The effective policy must be accept in both the chain header and the
    // provenance marker that the sidecar verification reads back.
    expect($nft)->toContain('type filter hook input priority -10; policy accept;')
        ->and($nft)->toContain('# tallpbx-policy: accept')
        ->and($nft)->toContain('# STAGE 12: DEFAULT INBOUND POLICY');

    $pending = $generator->writePending();
    try {
        expect($generator->validateSyntax($pending))->toBeTrue();
    } finally {
        @unlink($pending);
        @rmdir($tempDir);
    }
});

it('restores the enforcing ruleset when observe mode is turned off', function (): void {
    $baseline = (new SecurityConfigGenerator)->generate();

    SecuritySetting::set('firewall_observe_mode', true);
    $observed = (new SecurityConfigGenerator)->generate();
    expect($observed)->not->toBe($baseline);

    SecuritySetting::set('firewall_observe_mode', false);
    expect((new SecurityConfigGenerator)->generate())->toBe($baseline);
});

it('keeps the ruleset byte-identical when firewall_observe_mode is absent or false', function (): void {
    $default = (new SecurityConfigGenerator)->generate();

    SecuritySetting::set('firewall_observe_mode', false);

    expect((new SecurityConfigGenerator)->generate())->toBe($default);
});
