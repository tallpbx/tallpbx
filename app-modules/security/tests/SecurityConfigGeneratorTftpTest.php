<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for the hardened TFTP defense profile (STAGE 9).
 *
 * The defense rules must precede the port catalog's `udp dport 69 accept`
 * (a drop placed after an accept never fires), the flood meters must be
 * memory-bounded (`timeout` ages entries out, `size` caps the table), and
 * the whole build must pass the real nftables parser.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

it('emits the hardened tftp defense rules before the port catalog accept', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx_tftp_defense_test_'.uniqid();
    $generator = new SecurityConfigGenerator($tempDir);
    $nft = $generator->generate();

    expect($nft)->toContain('# STAGE 9: HARDENED TFTP DEFENSE PROFILE')
        // 1. Write requests (WRQ, opcode 2) — provisioning stays read-only.
        ->and($nft)->toContain('udp dport 69 @th,64,16 0x0002 counter drop')
        // 2. Directory traversal: read request (opcode 1) starting with '../'.
        ->and($nft)->toContain('udp dport 69 @th,64,16 0x0001 @th,80,24 0x2e2e2f counter drop')
        // 3. Malicious scan probes: read request starting with '/x'.
        ->and($nft)->toContain('udp dport 69 @th,64,16 0x0001 @th,80,16 0x2f78 counter drop')
        // 4. Per-IP flood meters for both address families.
        ->and($nft)->toContain('udp dport 69 update @tftp_flood4 { ip saddr limit rate over 10/minute burst 20 packets } counter drop')
        ->and($nft)->toContain('udp dport 69 update @tftp_flood6 { ip6 saddr limit rate over 10/minute burst 20 packets } counter drop');

    // Ordering: every defense rule sits before the catalog accept rule.
    $wrqPos = strpos($nft, '0x0002 counter drop');
    $acceptPos = strpos($nft, 'udp dport 69 accept');
    expect($acceptPos)->not->toBeFalse()
        ->and($wrqPos)->not->toBeFalse()
        ->and($wrqPos)->toBeLessThan($acceptPos);

    // The complete build passes the real nftables parser.
    $pending = $generator->writePending();
    try {
        expect($generator->validateSyntax($pending))->toBeTrue();
    } finally {
        @unlink($pending);
        @rmdir($tempDir);
    }
});

it('declares the flood meters with a size cap and an element timeout', function (): void {
    $nft = (new SecurityConfigGenerator)->generate();

    foreach (['tftp_flood4', 'tftp_flood6'] as $setName) {
        $needle = "set {$setName} {";
        $start = strpos($nft, $needle);
        expect($start)->not->toBeFalse();

        $block = substr($nft, $start, 220);

        // The cap and the age-out keep a spoofed-source flood from growing
        // kernel memory without limit.
        expect($block)->toContain('flags dynamic,timeout')
            ->and($block)->toContain('timeout 1m')
            ->and($block)->toContain('size 65535');
    }

    expect($nft)->toContain('set tftp_flood4 {')
        ->and($nft)->toContain('set tftp_flood6 {')
        ->and($nft)->toContain('type ipv4_addr', false)
        ->and($nft)->toContain('type ipv6_addr', false);
});

it('honours the administrator rate limit and burst settings', function (): void {
    SecuritySetting::set('tftp_defense_rate_limit', 30);
    SecuritySetting::set('tftp_defense_burst', 60);

    $nft = (new SecurityConfigGenerator)->generate();

    expect($nft)->toContain('udp dport 69 update @tftp_flood4 { ip saddr limit rate over 30/minute burst 60 packets } counter drop')
        ->and($nft)->toContain('udp dport 69 update @tftp_flood6 { ip6 saddr limit rate over 30/minute burst 60 packets } counter drop')
        ->and($nft)->not->toContain('limit rate over 10/minute');
});

it('clamps nonsensical rate limit and burst values to sensible bounds', function (): void {
    // Zero and negative values fall back to the recommended defaults.
    SecuritySetting::set('tftp_defense_rate_limit', 0);
    SecuritySetting::set('tftp_defense_burst', -5);

    $nft = (new SecurityConfigGenerator)->generate();
    expect($nft)->toContain('limit rate over 10/minute burst 20 packets');

    // Absurdly large values are capped so the meter still means something.
    SecuritySetting::set('tftp_defense_rate_limit', 999999999);
    SecuritySetting::set('tftp_defense_burst', 999999999);

    $nft = (new SecurityConfigGenerator)->generate();
    expect($nft)->toContain('limit rate over 10000/minute burst 10000 packets');
});

it('emits no tftp defense rules when the profile is disabled', function (): void {
    SecuritySetting::set('tftp_defense_enabled', false);

    $nft = (new SecurityConfigGenerator)->generate();

    expect($nft)->not->toContain('# STAGE 9')
        ->and($nft)->not->toContain('0x0002 counter drop')
        ->and($nft)->not->toContain('@tftp_flood4')
        ->and($nft)->not->toContain('@tftp_flood6')
        // The provisioning accept itself is the port catalog's business and
        // stays exactly as the administrator configured it.
        ->and($nft)->toContain('udp dport 69 accept');
});

it('keeps the ruleset byte-identical when the setting is absent or true', function (): void {
    $default = (new SecurityConfigGenerator)->generate();

    SecuritySetting::set('tftp_defense_enabled', true);

    expect((new SecurityConfigGenerator)->generate())->toBe($default);
});

it('observes the tftp defense rules without verdicts in global observe mode', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    $nft = (new SecurityConfigGenerator)->generate();

    expect($nft)->not->toContain('0x0002 counter drop')
        ->and($nft)->not->toContain('0x2e2e2f counter drop')
        ->and($nft)->not->toContain('0x2f78 counter drop')
        ->and($nft)->not->toContain('} counter drop')
        // The pattern and flood rules still evaluate and count, but enforce nothing.
        ->and($nft)->toContain('udp dport 69 @th,64,16 0x0002 counter log prefix "tallpbx-observe:tftp " limit rate 100/minute')
        ->and($nft)->toContain('udp dport 69 update @tftp_flood4 { ip saddr limit rate over 10/minute burst 20 packets } counter log prefix "tallpbx-observe:tftp " limit rate 100/minute')
        ->and($nft)->toContain('udp dport 69 update @tftp_flood6 { ip6 saddr limit rate over 10/minute burst 20 packets } counter log prefix "tallpbx-observe:tftp " limit rate 100/minute');
});

it('merges the reserved custom pattern setting into the read-request rules', function (): void {
    SecuritySetting::set('tftp_defense_custom_patterns', json_encode(['busybox']));

    $tempDir = sys_get_temp_dir().'/tallpbx_tftp_custom_test_'.uniqid();
    $generator = new SecurityConfigGenerator($tempDir);
    $nft = $generator->generate();

    // 'busybox' (7 bytes) becomes a 56-bit payload comparison after the
    // read-request opcode, in addition to the base patterns.
    expect($nft)->toContain('udp dport 69 @th,64,16 0x0001 @th,80,56 0x62757379626f78 counter drop')
        ->and($nft)->toContain('0x2e2e2f counter drop')
        ->and($nft)->toContain('0x2f78 counter drop');

    // The merged build still compiles against the real parser.
    $pending = $generator->writePending();
    try {
        expect($generator->validateSyntax($pending))->toBeTrue();
    } finally {
        @unlink($pending);
        @rmdir($tempDir);
    }
});
