<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Carbon;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for SecurityConfigGenerator.
 *
 * Verifies that MariaDB database configuration is compiled into valid Linux nftables
 * rulesets, including sets, standard PBX services, custom sequential rules, and syntax validation.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

it('generates valid nftables ruleset structure with invariants and default drop policy', function (): void {
    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    expect($nft)->toContain('#!/usr/sbin/nft -f')
        ->and($nft)->toContain('flush ruleset')
        ->and($nft)->toContain('table inet tallpbx_filter {')
        ->and($nft)->toContain('set blacklist_ips {')
        ->and($nft)->toContain('set banned_ips {')
        ->and($nft)->toContain('set whitelist_ips {')
        ->and($nft)->toContain('chain input {')
        ->and($nft)->toContain('type filter hook input priority -10; policy drop;')
        ->and($nft)->toContain('iif "lo" accept')
        ->and($nft)->toContain('ct state established,related accept')
        ->and($nft)->toContain('ct state invalid drop')
        ->and($nft)->toContain('ip protocol icmp icmp type echo-request limit rate 5/second burst 5 packets accept')
        ->and($nft)->toContain('icmpv6 type { packet-too-big, mld-listener-query, mld-listener-report, mld-listener-done, mld2-listener-report, nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert, nd-redirect } accept')
        ->and($nft)->toContain('chain forward {')
        ->and($nft)->toContain('chain output {');
});

it('includes blacklist and whitelist elements in generated sets', function (): void {
    SecurityIpList::create([
        'type' => 'blacklist',
        'ip_address' => '45.142.120.0/24',
        'description' => 'Malicious scanner block',
    ]);

    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '192.168.1.0/24',
        'description' => 'Office LAN',
    ]);

    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    expect($nft)->toContain('45.142.120.0/24')
        ->and($nft)->toContain('192.168.1.0/24')
        // Loopback trust comes from the STEP 1 `iif "lo"` interface rule,
        // not from an injected whitelist element.
        ->and($nft)->not->toContain('127.0.0.1')
        ->and($nft)->toContain('iif "lo" accept');
});

it('includes active unexpired bans in banned_ips set with kernel timeouts', function (): void {
    SecurityBan::create([
        'ip_address' => '198.51.100.99',
        'vector' => 'web_auth',
        'reason' => 'Test ban',
        'attempt_count' => 1,
        'banned_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addSeconds(3600),
        'is_active' => true,
    ]);

    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    expect($nft)->toContain('198.51.100.99')
        ->and($nft)->toMatch('/198\.51\.100\.99 timeout [0-9]+s/');
});

it('compiles standard PBX port catalog services into chain input', function (): void {
    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    // SIP phone ports
    expect($nft)->toContain('udp dport { 5060, 5061, 5080 } accept')
        ->and($nft)->toContain('tcp dport { 5060, 5061, 5080 } accept')
        // RTP voice media range
        ->and($nft)->toContain('udp dport 16384-32768 accept')
        // Web Admin portal
        ->and($nft)->toContain('tcp dport { 80, 443 } accept')
        // SSH Console
        ->and($nft)->toContain('tcp dport 22 accept');
});

it('compiles custom sequential firewall rules in ascending order', function (): void {
    SecurityRule::create([
        'sequence' => 20,
        'description' => 'Block untrusted branch',
        'source_ip' => '192.0.2.0/24',
        'custom_port' => '3000',
        'custom_protocol' => 'udp',
        'action' => 'drop',
        'enabled' => true,
    ]);

    SecurityRule::create([
        'sequence' => 10,
        'description' => 'Office VPN access',
        'source_ip' => '10.10.0.0/16',
        'custom_port' => '8080',
        'custom_protocol' => 'tcp',
        'action' => 'accept',
        'enabled' => true,
    ]);

    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    $posRule10 = strpos($nft, '# Rule 10: Office VPN access');
    $posRule20 = strpos($nft, '# Rule 20: Block untrusted branch');

    expect($posRule10)->not->toBeFalse()
        ->and($posRule20)->not->toBeFalse()
        ->and($posRule10)->toBeLessThan($posRule20)
        ->and($nft)->toContain('ip saddr 10.10.0.0/16 tcp dport 8080 accept')
        ->and($nft)->toContain('ip saddr 192.0.2.0/24 udp dport 3000 drop');
});

it('generates open ruleset when firewall_enabled is false', function (): void {
    SecuritySetting::set('firewall_enabled', false);

    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    expect($nft)->toContain('policy accept;')
        ->and($nft)->toContain('Firewall is currently disabled')
        ->and($nft)->not->toContain('ip saddr @blacklist_ips drop');
});

it('writes pending configuration and validates syntax with host nft utility', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx_security_test_'.uniqid();
    $generator = new SecurityConfigGenerator($tempDir);

    $pendingFile = $generator->writePending();

    expect(file_exists($pendingFile))->toBeTrue();

    // Validate syntax with real nft -c -f utility
    $isValid = $generator->validateSyntax($pendingFile);
    expect($isValid)->toBeTrue();

    // Clean up temporary test file
    @unlink($pendingFile);
    @rmdir($tempDir);
});

it('refuses to compile a ruleset when a ban contains an invalid address', function (): void {
    SecurityBan::create([
        'ip_address' => '344.34.34.34',
        'vector' => 'manual',
        'reason' => 'Legacy invalid ban',
        'attempt_count' => 1,
        'banned_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addHour(),
        'is_active' => true,
    ]);

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, "ban '344.34.34.34'");
});

it('refuses to compile a ruleset when entries are not valid addresses', function (): void {
    SecurityIpList::create([
        'type' => 'blacklist',
        'ip_address' => '10.0.0.0/99',
        'description' => 'Invalid CIDR prefix',
    ]);
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '1:::2',
        'description' => 'Malformed IPv6',
    ]);

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, "whitelist entry '1:::2'");
});

it('compiles IPv6 lists and bans into parallel ipv6 sets with mirrored pipeline rules', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '2001:569:fcd9:900:e95c:2439:5a28:86b',
        'description' => 'Office IPv6 uplink',
    ]);
    SecurityIpList::create([
        'type' => 'blacklist',
        'ip_address' => '2001:db8:bad::/48',
        'description' => 'Malicious IPv6 block',
    ]);
    SecurityBan::create([
        'ip_address' => '2001:db8::dead:beef',
        'vector' => 'manual',
        'reason' => 'IPv6 brute force',
        'attempt_count' => 1,
        'banned_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addHour(),
        'is_active' => true,
    ]);

    $nft = (new SecurityConfigGenerator)->generate();

    expect($nft)->toContain('set blacklist_ips6 {')
        ->and($nft)->toContain('set banned_ips6 {')
        ->and($nft)->toContain('set whitelist_ips6 {')
        ->and($nft)->toContain('elements = { 2001:569:fcd9:900:e95c:2439:5a28:86b }')
        ->and($nft)->not->toContain('::1')
        ->and($nft)->toContain('elements = { 2001:db8:bad::/48 }')
        ->and($nft)->toMatch('/2001:db8::dead:beef timeout [0-9]+s/')
        ->and($nft)->toContain('ip6 saddr @blacklist_ips6 drop')
        ->and($nft)->toContain('ip6 saddr @banned_ips6 drop')
        ->and($nft)->toContain('ip6 saddr @whitelist_ips6 accept');

    // The IPv6 entries must stay out of the IPv4 sets entirely.
    preg_match('/set blacklist_ips \{.*?\n    \}/s', $nft, $v4Blacklist);
    preg_match('/set whitelist_ips \{.*?\n    \}/s', $nft, $v4Whitelist);
    expect($v4Blacklist[1] ?? '')->not->toContain('2001:')
        ->and($v4Whitelist[1] ?? '')->not->toContain('2001:');

    // Pipeline order parity: v6 drops precede the stateful rules and the v6
    // whitelist accept precedes the IPv6 ICMP invariant.
    $blacklist6 = strpos($nft, 'ip6 saddr @blacklist_ips6 drop');
    $banned6 = strpos($nft, 'ip6 saddr @banned_ips6 drop');
    $ctRules = strpos($nft, 'ct state established,related accept');
    $whitelist6 = strpos($nft, 'ip6 saddr @whitelist_ips6 accept');
    $icmp6 = strpos($nft, 'mld2-listener-report');

    expect($blacklist6)->toBeLessThan($banned6)
        ->and($banned6)->toBeLessThan($ctRules)
        ->and($ctRules)->toBeLessThan($whitelist6)
        ->and($whitelist6)->toBeLessThan($icmp6);
});

it('keeps the essential IPv6 connectivity invariant without shadowing the ping policy', function (): void {
    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    // Essential ICMPv6 types (path-MTU discovery, MLD, and Neighbor Discovery)
    // stay unconditionally accepted so IPv6 connectivity never breaks.
    expect($nft)->toContain('ip6 nexthdr ipv6-icmp icmpv6 type { packet-too-big, mld-listener-query, mld-listener-report, mld-listener-done, mld2-listener-report, nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert, nd-redirect } accept')
        // The blanket all-ICMPv6 accept is gone: it used to shadow the
        // rate-limited echo-request rule and defeat stealth mode.
        ->and($nft)->not->toContain('ip6 nexthdr ipv6-icmp accept')
        // The ping policy itself remains present, carrying the configured rate limit.
        ->and($nft)->toContain('ip6 nexthdr ipv6-icmp icmpv6 type echo-request limit rate 5/second burst 5 packets accept');

    // Stealth mode must silence ping for BOTH families while the connectivity
    // invariant stays in place.
    SecurityService::system()->where('protocol', 'icmp')->first()->update(['enabled' => false]);

    $stealth = $generator->generate();

    expect($stealth)->not->toContain('icmp type echo-request')
        ->and($stealth)->not->toContain('icmpv6 type echo-request')
        ->and($stealth)->toContain('mld2-listener-report')
        ->and($stealth)->toContain('nd-neighbor-solicit');
});

it('applies the ICMP ping source restriction to the matching address family and still validates', function (): void {
    $icmpService = SecurityService::system()->where('protocol', 'icmp')->first();

    // An IPv4 restriction must only prefix the IPv4 rule; the IPv6 rule keeps
    // its own unrestricted form and the whole file must pass real nft -c.
    $icmpService->update(['source_ip' => '203.0.113.0/24']);

    $tempDir = sys_get_temp_dir().'/tallpbx_icmp_family_test_'.uniqid();
    $generator = new SecurityConfigGenerator($tempDir);

    $nft = $generator->generate();

    expect($nft)->toContain('ip saddr 203.0.113.0/24 ip protocol icmp icmp type echo-request')
        ->and($nft)->not->toContain('ip saddr 203.0.113.0/24 ip6 nexthdr')
        ->and($nft)->toContain('ip6 nexthdr ipv6-icmp icmpv6 type echo-request');

    $pending = $generator->writePending();
    try {
        expect($generator->validateSyntax($pending))->toBeTrue();
    } finally {
        @unlink($pending);
        @rmdir($tempDir);
    }

    // An IPv6 restriction goes the other way around.
    $icmpService->update(['source_ip' => '2001:db8::/32']);
    $v6Nft = (new SecurityConfigGenerator)->generate();

    expect($v6Nft)->toContain('ip6 saddr 2001:db8::/32 ip6 nexthdr ipv6-icmp icmpv6 type echo-request')
        ->and($v6Nft)->not->toContain('ip saddr 2001:db8::/32');

    // Malformed source values refuse compilation instead of silently
    // dropping the restriction.
    $icmpService->update(['source_ip' => 'not-an-address']);

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, 'not a valid IPv4 or IPv6');
});

it('emits a valid ruleset with empty whitelist sets when no entries exist', function (): void {
    // No whitelist rows exist in the database (each test runs in an isolated
    // transactional database); loopback must remain trusted through the
    // interface rule alone, and empty sets must not emit empty element braces.
    $tempDir = sys_get_temp_dir().'/tallpbx_empty_whitelist_test_'.uniqid();
    $generator = new SecurityConfigGenerator($tempDir);

    $nft = $generator->generate();

    expect($nft)->toContain('set whitelist_ips {')
        ->and($nft)->toContain('set whitelist_ips6 {')
        ->and($nft)->not->toContain('elements = {  }')
        ->and($nft)->not->toContain('elements = { }')
        ->and($nft)->not->toContain('127.0.0.1')
        ->and($nft)->not->toContain('::1')
        ->and($nft)->toContain('iif "lo" accept');

    $pending = $generator->writePending();
    try {
        expect($generator->validateSyntax($pending))->toBeTrue();
    } finally {
        @unlink($pending);
        @rmdir($tempDir);
    }
});

it('emits provenance markers and canonical digest in the generated ruleset', function (): void {
    $generator = new SecurityConfigGenerator;
    $nft = $generator->generate();

    expect($nft)->toMatch('/^# tallpbx-policy: (drop|accept)$/m')
        ->and($nft)->toMatch('/^# tallpbx-digest: sha256:[0-9a-f]{64}$/m');

    $digest = $generator->canonicalDigest();
    expect($digest)->toMatch('/^sha256:[0-9a-f]{64}$/')
        ->and($nft)->toContain("# tallpbx-digest: {$digest}");
});

it('keeps canonical digest stable when dynamic attacker bans are added or decay', function (): void {
    $generator = new SecurityConfigGenerator;
    $baselineDigest = $generator->canonicalDigest();

    // Adding an active dynamic attacker ban should NOT alter the canonical ruleset digest
    // because dynamic bans are manipulated out-of-band in kernel RAM.
    $ban = SecurityBan::create([
        'ip_address' => '198.51.100.77',
        'vector' => 'sip_auth',
        'reason' => 'Dynamic ban stability test',
        'attempt_count' => 5,
        'banned_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addSeconds(3600),
        'is_active' => true,
    ]);

    expect($generator->canonicalDigest())->toBe($baselineDigest);

    // Decaying the countdown timer should also NOT alter the canonical digest
    $ban->update([
        'expires_at' => Carbon::now()->addSeconds(60),
    ]);

    expect($generator->canonicalDigest())->toBe($baselineDigest);
});

it('changes canonical digest when permanent whitelist, blacklist, rules, services, or policy change', function (): void {
    $generator = new SecurityConfigGenerator;
    $baselineDigest = $generator->canonicalDigest();

    // 1. Permanent whitelist change
    $whitelistEntry = SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '192.0.2.100',
        'description' => 'Test whitelist',
    ]);
    $digestAfterWhitelist = $generator->canonicalDigest();
    expect($digestAfterWhitelist)->not->toBe($baselineDigest);
    $whitelistEntry->delete();
    expect($generator->canonicalDigest())->toBe($baselineDigest);

    // 2. Permanent blacklist change
    $blacklistEntry = SecurityIpList::create([
        'type' => 'blacklist',
        'ip_address' => '198.51.100.0/24',
        'description' => 'Test blacklist',
    ]);
    $digestAfterBlacklist = $generator->canonicalDigest();
    expect($digestAfterBlacklist)->not->toBe($baselineDigest);
    $blacklistEntry->delete();
    expect($generator->canonicalDigest())->toBe($baselineDigest);

    // 3. Custom rule change
    $rule = SecurityRule::create([
        'sequence' => 99,
        'description' => 'Custom test rule',
        'action' => 'drop',
        'source_ip' => 'any',
        'custom_protocol' => 'tcp',
        'custom_port' => '9999',
        'is_active' => true,
    ]);
    $digestAfterRule = $generator->canonicalDigest();
    expect($digestAfterRule)->not->toBe($baselineDigest);
    $rule->delete();
    expect($generator->canonicalDigest())->toBe($baselineDigest);

    // 4. Default policy change
    SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => 'accept']);
    $digestAfterPolicy = $generator->canonicalDigest();
    expect($digestAfterPolicy)->not->toBe($baselineDigest);
    SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => 'drop']);
    expect($generator->canonicalDigest())->toBe($baselineDigest);
});
