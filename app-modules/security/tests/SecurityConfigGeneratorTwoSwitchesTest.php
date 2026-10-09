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
 * Verifies that turning the pre-filter off skips its protection stages while
 * the remaining chain (ICMP, TFTP defense, port catalog, custom rules, and
 * the stored default policy) stays active, and documents exactly which drop
 * rules remain. The stored default policy is NOT silently changed — the
 * generated ruleset of this shape would drop the server's loopback database
 * and cache connections, which is why the post-toggle connectivity check in
 * LockoutGuardService refuses to apply it unless the administrator has made
 * it safe (default policy Accept). Also covers the switch defaults never
 * drifting from the fallbacks.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

/**
 * Collect the chain's drop rules (verdict-bearing lines, comments excluded).
 *
 * @return array<int, string>
 */
function prefilterOffDropRules(string $nft): array
{
    return array_values(array_filter(
        explode("\n", $nft),
        static fn (string $line): bool => str_contains($line, ' drop')
            && ! str_starts_with(ltrim($line), '#')
            && ! str_contains($line, 'policy')
            // The STAGE 12 stage emits the bare stored verdict on its own line
            // (e.g. "        drop"); that is the default policy itself, not an
            // authored drop rule, so it must not be counted here.
            && trim($line) !== 'drop',
    ));
}

it('skips the protection stages, keeps the stored policy, and documents the remaining drop rules', function (): void {
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
    // original design grants (the lockout guard, not a pinned rule, is the protection).
    expect($nft)->not->toContain('iif "lo" accept')
        ->and($nft)->not->toContain('ip saddr @whitelist_ips accept')
        ->and($nft)->not->toContain('ip6 saddr @whitelist_ips6 accept')
        ->and($nft)->not->toContain('ct state invalid drop')
        ->and($nft)->not->toContain('ct state established,related accept')
        ->and($nft)->not->toContain('ip saddr @blacklist_ips drop')
        ->and($nft)->not->toContain('ip saddr @banned_ips drop')
        ->and($nft)->not->toContain('ip saddr @threat_feed_ips counter drop');

    // The stored default policy is honored exactly as saved — never
    // silently changed by the switch.
    expect($nft)->toContain('type filter hook input priority -10; policy drop;')
        ->and($nft)->toContain('# tallpbx-policy: drop');

    // The rest of the chain still runs: ICMP, the port catalog, the custom
    // rules the administrator authors, and the default-policy stage marker.
    expect($nft)->toContain('ip protocol icmp icmp type echo-request')
        ->and($nft)->toContain('udp dport { 5060, 5061, 5080 } accept')
        ->and($nft)->toContain('# Rule 5: Kept custom rule')
        ->and($nft)->toContain('# STAGE 12: DEFAULT INBOUND POLICY')
        ->and($nft)->toContain('tcp dport { 80, 443 }')
        ->and($nft)->toContain('tcp dport 22');

    // Every remaining drop rule is an explicit protocol-level defense: with
    // no custom drops authored, only the TFTP defense profile may drop. All
    // other traffic — including loopback database and cache connections —
    // falls through to the stored default policy, which is what the
    // connectivity check protects against.
    $dropRules = prefilterOffDropRules($nft);
    expect($dropRules)->toHaveCount(5);
    foreach ($dropRules as $rule) {
        expect(trim($rule))->toStartWith('udp dport 69');
    }

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

it('keeps explicitly authored custom drop rules in force while the pre-filter is off', function (): void {
    SecurityRule::create([
        'sequence' => 30,
        'description' => 'Block RDP scanners',
        'source_ip' => 'any',
        'custom_port' => '3389',
        'custom_protocol' => 'tcp',
        'action' => 'drop',
        'enabled' => true,
    ]);

    SecuritySetting::set('prefilter_enabled', false);

    $nft = (new SecurityConfigGenerator)->generate();

    // Explicit administrator rules are enforcement, not a default: they keep
    // dropping while everything unmatched falls through to the stored policy.
    $dropRules = prefilterOffDropRules($nft);
    expect($dropRules)->toHaveCount(6);

    // Exactly one of the six is not part of the TFTP defense profile: the
    // administrator's own RDP rule, kept verbatim.
    $authoredDrops = array_values(array_filter(
        $dropRules,
        static fn (string $rule): bool => ! str_starts_with(trim($rule), 'udp dport 69'),
    ));

    expect($authoredDrops)->toHaveCount(1)
        ->and(trim($authoredDrops[0]))->toBe('tcp dport 3389 drop');
});

it('keeps the ruleset byte-identical when prefilter_enabled is absent or true', function (): void {
    $default = (new SecurityConfigGenerator)->generate();

    SecuritySetting::set('prefilter_enabled', true);

    expect((new SecurityConfigGenerator)->generate())->toBe($default);
});
