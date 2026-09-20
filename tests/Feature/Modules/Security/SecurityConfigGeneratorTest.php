<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Carbon;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
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
        ->and($nft)->toContain('ip6 nexthdr ipv6-icmp accept')
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
        ->and($nft)->toContain('127.0.0.1'); // Loopback is always preserved in whitelist set
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

it('refuses to compile a ruleset when IPv6 or malformed entries exist in the IPv4 sets', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '2001:569:fcd9:900:e95c:2439:5a28:86b',
        'description' => 'Legacy IPv6 entry',
    ]);
    SecurityIpList::create([
        'type' => 'blacklist',
        'ip_address' => '10.0.0.0/99',
        'description' => 'Legacy invalid CIDR',
    ]);

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, "whitelist entry '2001:569:fcd9:900:e95c:2439:5a28:86b'");
});
