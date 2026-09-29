<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\SipScannerDetected;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;

/**
 * Feature tests for the SIP scanner event listener.
 *
 * The listener validates the attacker address, exempts trusted addresses,
 * short-circuits on an existing active ban, applies the two-tier rules
 * (high-confidence bans, low-confidence records and escalates on distinct
 * signatures), and always bans through SecurityBanService — never the
 * kernel executor directly.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);

    $this->banService = Mockery::mock(SecurityBanServiceInterface::class);
    $this->app->instance(SecurityBanServiceInterface::class, $this->banService);

    clearLowSignatureKeys();
});

afterEach(function (): void {
    clearLowSignatureKeys();
});

/**
 * Remove the Redis escalation keys so tests never leak window state.
 */
function clearLowSignatureKeys(): void
{
    $keys = Redis::keys('tallpbx:security:sip_scanner_low:*');

    if (! empty($keys)) {
        Redis::del($keys);
    }
}

/**
 * Build a scanner detection event with the given attacker IP and signature.
 */
function scannerEvent(
    string $ip,
    string $value = 'friendly-scanner',
    string $confidence = 'high',
    string $type = 'User-Agent',
): SipScannerDetected {
    return new SipScannerDetected(
        eventName: 'CUSTOM',
        headers: [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'tallpbx::sip_scanner_detected',
            'Scanner-Type' => $type,
            'Scanner-Value' => $value,
            'Scanner-Confidence' => $confidence,
            'Attacker-IP' => $ip,
        ],
        body: '',
    );
}

it('exposes the scanner fields through the typed event accessors', function (): void {
    $event = scannerEvent('203.0.113.5', 'sipcli/v1.8', 'high', 'User-Agent');

    expect($event->attackerIp())->toBe('203.0.113.5')
        ->and($event->scannerType())->toBe('User-Agent')
        ->and($event->scannerValue())->toBe('sipcli/v1.8')
        ->and($event->confidence())->toBe('high');

    // A missing confidence header defaults to high (the design's contract).
    $bare = new SipScannerDetected('CUSTOM', ['Attacker-IP' => '203.0.113.5'], '');
    expect($bare->confidence())->toBe('high');
});

it('discards events whose attacker address is not a valid IP', function (): void {
    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('not-an-ip'));
    event(scannerEvent(''));

    expect(SecurityBan::count())->toBe(0)
        ->and(SecurityAuditLog::where('action', 'sip_scanner_detected')->count())->toBe(0);
});

it('never auto-bans an address in the trusted list', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);
    SecurityIpList::create(['type' => 'whitelist', 'ip_address' => '203.0.113.9']);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.9'));

    expect(SecurityBan::count())->toBe(0);
});

it('records a high-confidence match without banning while enforcement is off', function (): void {
    // The shipped default: detection and recording are always on.
    SecuritySetting::set('sip_scanner_enforcement_enabled', false);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.10', 'friendly-scanner', 'high', 'User-Agent'));

    $row = SecurityBan::where('ip_address', '203.0.113.10')->first();

    // The incident is recorded as a non-enforcing row: every enforcement
    // consumer reads SecurityBan::active() only, so nothing is blocked and
    // the reconciler never sees it.
    expect($row)->not->toBeNull()
        ->and($row->is_active)->toBeFalse()
        ->and($row->vector)->toBe('sip_scanner')
        ->and($row->reason)->toContain('friendly-scanner')
        ->and($row->reason)->toContain('User-Agent')
        ->and($row->reason)->toContain('high');

    expect(SecurityAuditLog::where('action', 'sip_scanner_detected')->where('ip_address', '203.0.113.10')->exists())->toBeTrue();
});

it('bans a high-confidence match through the ban service when enforcement is on', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);
    SecuritySetting::set('sip_scanner_ban_seconds', 86400);

    $this->banService->shouldReceive('ban')
        ->once()
        ->with(
            '203.0.113.11',
            'sip_scanner',
            Mockery::on(fn (string $reason): bool => str_contains($reason, 'friendly-scanner')
                && str_contains($reason, 'User-Agent')
                && str_contains($reason, 'high')),
            86400,
        );

    // Dispatching through the event dispatcher also proves the listener is
    // registered for the typed event.
    event(scannerEvent('203.0.113.11'));
});

it('passes a permanent ban duration as null when the setting is zero', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);
    SecuritySetting::set('sip_scanner_ban_seconds', 0);

    $this->banService->shouldReceive('ban')
        ->once()
        ->with('203.0.113.12', 'sip_scanner', Mockery::type('string'), null);

    event(scannerEvent('203.0.113.12', 'sipvicious', 'high', 'From-User'));
});

it('processes the same event when it arrives as a generic custom event', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);
    SecuritySetting::set('sip_scanner_ban_seconds', 86400);

    $this->banService->shouldReceive('ban')->once()->with('203.0.113.13', 'sip_scanner', Mockery::type('string'), 86400);

    // FreeSWITCH ESL may dispatch the event as the generic CustomEvent class
    // when the typed mapping is unavailable; the subclass guard still fires.
    event(new CustomEvent(
        eventName: 'CUSTOM',
        headers: [
            'Event-Subclass' => 'tallpbx::sip_scanner_detected',
            'Scanner-Type' => 'User-Agent',
            'Scanner-Value' => 'Ozeki',
            'Scanner-Confidence' => 'high',
            'Attacker-IP' => '203.0.113.13',
        ],
        body: '',
    ));
});

it('short-circuits when an active ban already exists for the address', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);

    SecurityBan::create([
        'ip_address' => '203.0.113.14',
        'vector' => 'sip_auth',
        'reason' => 'Existing active ban',
        'attempt_count' => 1,
        'banned_at' => now(),
        'is_active' => true,
    ]);

    // No second ban, no second audit entry, no second privileged operation.
    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.14'));

    expect(SecurityBan::where('ip_address', '203.0.113.14')->count())->toBe(1)
        ->and(SecurityAuditLog::where('ip_address', '203.0.113.14')->count())->toBe(0);
});

it('escalates two distinct low-confidence signatures within the window', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);
    SecuritySetting::set('sip_scanner_ban_seconds', 86400);

    // First low-confidence signature: recorded, never banned on its own.
    event(scannerEvent('203.0.113.15', 'SIP Call', 'low'));

    $incident = SecurityBan::where('ip_address', '203.0.113.15')->first();
    expect($incident)->not->toBeNull()
        ->and($incident->is_active)->toBeFalse();

    // A second, distinct low-confidence signature within the window stops
    // being generic: it escalates to a ban.
    $this->banService->shouldReceive('ban')
        ->once()
        ->with('203.0.113.15', 'sip_scanner', Mockery::on(fn (string $reason): bool => str_contains($reason, 'Generic Softphone')), 86400);

    event(scannerEvent('203.0.113.15', 'Generic Softphone', 'low'));
});

it('does not escalate a repeated identical low-confidence signature', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.16', 'SIP Call', 'low'));
    event(scannerEvent('203.0.113.16', 'SIP Call', 'low'));

    $incident = SecurityBan::where('ip_address', '203.0.113.16')->first();
    expect($incident)->not->toBeNull()
        ->and($incident->is_active)->toBeFalse()
        // Both detections update the same incident record instead of
        // creating a second row.
        ->and($incident->attempt_count)->toBe(2);
});

it('does not escalate while enforcement is off', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', false);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.17', 'SIP Call', 'low'));
    event(scannerEvent('203.0.113.17', 'Generic Softphone', 'low'));

    $incident = SecurityBan::where('ip_address', '203.0.113.17')->first();
    expect($incident)->not->toBeNull()
        ->and($incident->is_active)->toBeFalse();
});

it('truncates an over-long attacker-controlled signature value', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', false);

    $this->banService->shouldNotReceive('ban');

    // A malicious client controls its whole User-Agent; the value must never
    // overflow the 255-character reason column and crash the listener.
    event(scannerEvent('203.0.113.18', str_repeat('a', 400), 'high'));

    $incident = SecurityBan::where('ip_address', '203.0.113.18')->first();

    expect($incident)->not->toBeNull()
        ->and($incident->is_active)->toBeFalse()
        ->and(mb_strlen($incident->reason))->toBeLessThanOrEqual(255);
});
