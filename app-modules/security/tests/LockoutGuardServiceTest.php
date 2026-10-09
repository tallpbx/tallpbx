<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecuritySetting;
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

it('considers an IPv6 administrator safe when covered by a whitelisted IPv6 address', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '2001:db8::7',
        'description' => 'IPv6 admin uplink',
    ]);

    $guard = new LockoutGuardService;

    expect($guard->isIpSafe('2001:db8::7', 'drop'))->toBeTrue()
        ->and($guard->isWhitelisted('2001:db8::7'))->toBeTrue();
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

it('reports local services safe while the pre-filter stays on', function (): void {
    $guard = new LockoutGuardService;

    // The pre-filter's unconditional loopback accept is what keeps the
    // panel's own database and cache connections reachable; with it on,
    // nothing local is ever at risk.
    expect($guard->wouldDropLocalServices())->toBeFalse();
});

it('detects unsafe local services when pre-filters are off with a blocking default policy and no custom rules', function (): void {
    $guard = new LockoutGuardService;

    // Disabling pre-filters while the default policy is drop removes the loopback
    // and connection tracking rules, causing local connections to drop.
    expect($guard->wouldDropLocalServices(proposedPrefilterEnabled: false))->toBeTrue();

    // Same verdict once the switch is already off and nothing changes it.
    SecuritySetting::set('prefilter_enabled', false);

    expect($guard->wouldDropLocalServices())->toBeTrue()
        ->and($guard->wouldDropLocalServices(proposedPrefilterEnabled: true))->toBeFalse();
});

it('reports local services safe when pre-filters are off if custom lo and ct state rules exist', function (): void {
    $guard = new LockoutGuardService;

    SecurityRule::create([
        'sequence' => 10,
        'description' => 'Custom Loopback Accept',
        'source_ip' => '127.0.0.1',
        'action' => 'accept',
        'enabled' => true,
    ]);

    SecurityRule::create([
        'sequence' => 20,
        'description' => 'Custom ct state established,related Accept',
        'source_ip' => 'any',
        'action' => 'accept',
        'enabled' => true,
    ]);

    expect($guard->wouldDropLocalServices(proposedPrefilterEnabled: false))->toBeFalse();
});

it('reports local services safe when the default policy allows', function (): void {
    SecuritySetting::set('firewall_default_policy', 'accept');

    $guard = new LockoutGuardService;

    expect($guard->wouldDropLocalServices(proposedPrefilterEnabled: false))->toBeFalse();

    // The proposed (not yet stored) allow policy also protects local services
    // while the stored policy still blocks.
    SecuritySetting::set('firewall_default_policy', 'drop');

    expect($guard->wouldDropLocalServices(proposedDefaultPolicy: 'accept', proposedPrefilterEnabled: false))->toBeFalse();
});

it('reports local services safe in observe mode', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    $guard = new LockoutGuardService;

    // While observing, the chain policy is forced to accept — nothing drops.
    expect($guard->wouldDropLocalServices(proposedPrefilterEnabled: false))->toBeFalse();

    // Entering observe mode in the same change is safe for the same reason.
    SecuritySetting::set('firewall_observe_mode', false);

    expect($guard->wouldDropLocalServices(proposedObserveMode: true, proposedPrefilterEnabled: false))->toBeFalse();
});

it('reports local services safe when the firewall is disabled', function (): void {
    SecuritySetting::set('firewall_enabled', false);

    $guard = new LockoutGuardService;

    // A disabled firewall emits a fully open ruleset — no default drop.
    expect($guard->wouldDropLocalServices(proposedPrefilterEnabled: false))->toBeFalse();
});

it('throws LockoutException on assertLocalServicesSafe when pre-filter is off under default drop', function (): void {
    $guard = new LockoutGuardService;

    expect(fn () => $guard->assertLocalServicesSafe(proposedPrefilterEnabled: false))
        ->toThrow(LockoutException::class, 'Local service safety alert');
});

it('keeps assertLocalServicesSafe quiet when local services stay protected', function (): void {
    $guard = new LockoutGuardService;

    expect(fn () => $guard->assertLocalServicesSafe())->not->toThrow(LockoutException::class);
});
