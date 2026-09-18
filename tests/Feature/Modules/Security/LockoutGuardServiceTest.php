<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Services\LockoutGuardService;

/**
 * Feature tests for LockoutGuardService.
 *
 * Verifies that administrator sessions are strictly protected against accidental
 * firewall lockout when the default inbound policy is set to DROP.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

it('considers any IP safe when proposed default policy is accept', function (): void {
    $guard = new LockoutGuardService;

    expect($guard->isIpSafe('203.0.113.50', 'accept'))->toBeTrue();
});

it('considers loopback addresses safe regardless of policy', function (): void {
    $guard = new LockoutGuardService;

    expect($guard->isIpSafe('127.0.0.1', 'drop'))->toBeTrue()
        ->and($guard->isIpSafe('::1', 'drop'))->toBeTrue();
});

it('considers IP safe when covered by Trusted whitelist', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.50',
        'description' => 'Admin static IP',
    ]);

    $guard = new LockoutGuardService;

    expect($guard->isIpSafe('203.0.113.50', 'drop'))->toBeTrue()
        ->and($guard->isWhitelisted('203.0.113.50'))->toBeTrue();
});

it('considers IP safe when covered by a whitelisted CIDR subnet', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '192.168.10.0/24',
        'description' => 'Office subnet',
    ]);

    $guard = new LockoutGuardService;

    expect($guard->isIpSafe('192.168.10.77', 'drop'))->toBeTrue()
        ->and($guard->isWhitelisted('192.168.10.77'))->toBeTrue();
});

it('detects unsafe IP when setting policy to drop without whitelist or rule', function (): void {
    $guard = new LockoutGuardService;

    expect($guard->isIpSafe('203.0.113.50', 'drop'))->toBeFalse();
});

it('throws LockoutException on assertSafe when IP would be locked out', function (): void {
    $guard = new LockoutGuardService;

    expect(fn () => $guard->assertSafe('203.0.113.50', 'drop'))
        ->toThrow(LockoutException::class, 'Zero-Lockout Safety Alert');
});

it('adds IP to Trusted list via 1-click whitelistIp rescue helper', function (): void {
    $guard = new LockoutGuardService;

    expect($guard->isIpSafe('198.51.100.22', 'drop'))->toBeFalse();

    $entry = $guard->whitelistIp('198.51.100.22', '1-Click Rescue');

    expect($entry)->toBeInstanceOf(SecurityIpList::class)
        ->and($entry->type)->toBe('whitelist')
        ->and($entry->ip_address)->toBe('198.51.100.22');

    expect($guard->isIpSafe('198.51.100.22', 'drop'))->toBeTrue();
});

it('considers IP safe when covered by an explicit active ACCEPT rule', function (): void {
    SecurityRule::create([
        'sequence' => 10,
        'description' => 'Explicit Admin Allow',
        'source_ip' => '203.0.113.50',
        'action' => 'accept',
        'enabled' => true,
    ]);

    $guard = new LockoutGuardService;

    expect($guard->isIpSafe('203.0.113.50', 'drop'))->toBeTrue();
});

it('correctly reports blacklisted status', function (): void {
    SecurityIpList::create([
        'type' => 'blacklist',
        'ip_address' => '198.51.100.88',
        'description' => 'Known malicious scanner',
    ]);

    $guard = new LockoutGuardService;

    expect($guard->isBlacklisted('198.51.100.88'))->toBeTrue()
        ->and($guard->isBlacklisted('198.51.100.89'))->toBeFalse();
});
