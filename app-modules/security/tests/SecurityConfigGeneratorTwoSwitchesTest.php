<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for the whole-firewall and pre-filter on/off switches.
 *
 * Verifies that turning the pre-filter off skips exactly stages 1–7 while the
 * remaining chain (ICMP, port catalog, custom rules, default policy) stays
 * active, and that the switch defaults can never drift from the fallbacks.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

it('skips the entire pre-filter chain when prefilter_enabled is false', function (): void {
    SecurityRule::create([
        'sequence' => 5,
        'description' => 'Kept custom rule',
        'source_ip' => '203.0.113.77',
        'custom_port' => '9443',
        'custom_protocol' => 'tcp',
        'action' => 'accept',
        'enabled' => true,
    ]);

    SecuritySetting::set('prefilter_enabled', false);

    $tempDir = sys_get_temp_dir().'/tallpbx_prefilter_off_test_'.uniqid();
    $generator = new SecurityConfigGenerator($tempDir);
    $nft = $generator->generate();

    // Stages 1–7 are gone entirely — including loopback, the whitelist, the
    // malformed-packet drop, and the stateful fast path, exactly as the
    // design grants (the lockout guard, not a pinned rule, is the protection).
    expect($nft)->not->toContain('iif "lo" accept')
        ->and($nft)->not->toContain('ip saddr @whitelist_ips accept')
        ->and($nft)->not->toContain('ip6 saddr @whitelist_ips6 accept')
        ->and($nft)->not->toContain('ct state invalid drop')
        ->and($nft)->not->toContain('ct state established,related accept')
        ->and($nft)->not->toContain('ip saddr @blacklist_ips drop')
        ->and($nft)->not->toContain('ip saddr @banned_ips drop')
        ->and($nft)->not->toContain('ip saddr @threat_feed_ips counter drop');

    // The rest of the chain still runs: ICMP, the port catalog, the custom
    // rules the administrator authors, and the default policy.
    expect($nft)->toContain('ip protocol icmp icmp type echo-request')
        ->and($nft)->toContain('udp dport { 5060, 5061, 5080 } accept')
        ->and($nft)->toContain('# Rule 5: Kept custom rule')
        ->and($nft)->toContain('# STAGE 12: DEFAULT INBOUND POLICY');

    // The sets stay declared so re-enabling the pre-filter repopulates the
    // kernel without a schema change.
    expect($nft)->toContain('set whitelist_ips {')
        ->and($nft)->toContain('set threat_feed_ips {');

    $pending = $generator->writePending();
    try {
        expect($generator->validateSyntax($pending))->toBeTrue();
    } finally {
        @unlink($pending);
        @rmdir($tempDir);
    }
});

it('keeps the ruleset byte-identical when prefilter_enabled is absent or true', function (): void {
    $default = (new SecurityConfigGenerator)->generate();

    SecuritySetting::set('prefilter_enabled', true);

    expect((new SecurityConfigGenerator)->generate())->toBe($default);
});
