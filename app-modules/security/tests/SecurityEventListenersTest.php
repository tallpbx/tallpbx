<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Events\FreeSwitch\CustomEvent;
use App\Events\FreeSwitch\SipScannerDetected;
use App\Events\FreeSwitch\SofiaFailedAuth;
use App\Events\FreeSwitch\XmlHandlerFailedAuth;
use App\Models\Admin;
use App\Services\FreeSwitchServiceInterface;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityIncidentServiceInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Bans\SecurityIncidentService;

/**
 * Feature tests for Security Event Listeners (XML Handler Auth, Web Login, SIP Auth, SIP Scanner).
 *
 * Consolidates event-driven incident handling across web and FreeSWITCH telephony vectors.
 */
beforeEach(function (): void {
    SecurityIpList::query()->delete();
    SecurityBan::query()->delete();
    SecurityAuditLog::query()->delete();

    $this->seed(SecurityServiceSeeder::class);

    $this->banService = Mockery::mock(SecurityBanServiceInterface::class);
    $this->app->instance(SecurityBanServiceInterface::class, $this->banService);

    // Clear test redis keys if any
    try {
        $prefix = (string) config('database.redis.options.prefix', '');
        foreach (['tallpbx:security:attempts:*', 'tallpbx:security:sip_scanner_low:*', 'tallpbx:security:sip_scanner_audited:*'] as $pattern) {
            $keys = Redis::keys($pattern);
            if (! empty($keys)) {
                $unprefixed = array_map(
                    static fn (string $key): string => str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key,
                    $keys
                );
                Redis::del($unprefixed);
            }
        }
    } catch (\Throwable) {
        // Ignore if Redis in-memory mock or non-connected in test environment
    }
});

afterEach(function (): void {
    try {
        $prefix = (string) config('database.redis.options.prefix', '');
        foreach (['tallpbx:security:attempts:*', 'tallpbx:security:sip_scanner_low:*', 'tallpbx:security:sip_scanner_audited:*'] as $pattern) {
            $keys = Redis::keys($pattern);
            if (! empty($keys)) {
                $unprefixed = array_map(
                    static fn (string $key): string => str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key,
                    $keys
                );
                Redis::del($unprefixed);
            }
        }
    } catch (\Throwable) {
        // Ignore
    }
});

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

/*
|--------------------------------------------------------------------------
| XML Handler Authentication Failure Listener Tests
|--------------------------------------------------------------------------
*/

it('captures XmlHandlerFailedAuth event and forwards to SecurityIncidentService', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with(
            '203.0.113.42',
            'xml_handler',
            'Reason: token_mismatch, Section: directory'
        );

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new XmlHandlerFailedAuth(
        ip: '203.0.113.42',
        reason: 'token_mismatch',
        section: 'directory',
    );

    event($event);
});

it('discards XmlHandlerFailedAuth event when IP address is empty or whitespace', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldNotReceive('recordFailure');

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new XmlHandlerFailedAuth(
        ip: '   ',
        reason: 'missing_token',
        section: 'dialplan',
    );

    event($event);
});

/*
|--------------------------------------------------------------------------
| Web UI Authentication Failure Listener Tests
|--------------------------------------------------------------------------
*/

it('registers LogFailedLoginListener for Illuminate\Auth\Events\Failed', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with('198.51.100.25', 'web_auth', Mockery::type('string'));

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    request()->server->set('REMOTE_ADDR', '198.51.100.25');

    $admin = Admin::factory()->make(['email' => 'attacker@test.com']);
    event(new Failed(
        guard: 'admin',
        user: $admin,
        credentials: ['email' => 'attacker@test.com', 'password' => 'wrong-pass']
    ));
});

it('captures failed logins via post to /panel/login', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->atLeast()->once()
        ->with('203.0.113.88', 'web_auth', Mockery::type('string'));

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.88'])
        ->post('/panel/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'badpassword',
        ]);
});

it('exempts whitelisted IP addresses from failure tracking', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '192.168.10.50',
        'description' => 'Office trusted workstation',
    ]);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldNotReceive('ban');

    $incidentService = new SecurityIncidentService($banService);

    for ($i = 0; $i < 10; $i++) {
        $incidentService->recordFailure('192.168.10.50', 'web_auth', 'Failed password attempt');
    }

    expect($incidentService->isWhitelisted('192.168.10.50'))->toBeTrue();
});

it('exempts whitelisted CIDR subnets from failure tracking', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '10.50.0.0/16',
        'description' => 'Internal office subnet',
    ]);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldNotReceive('ban');

    $incidentService = new SecurityIncidentService($banService);

    expect($incidentService->isWhitelisted('10.50.12.34'))->toBeTrue()
        ->and($incidentService->isWhitelisted('10.51.0.1'))->toBeFalse();
});

it('triggers ban when failures exceed max_retry threshold', function (): void {
    SecuritySetting::set('max_retry', 3);
    SecuritySetting::set('find_time', 300);
    SecuritySetting::set('ban_time', 1800);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldReceive('ban')
        ->once()
        ->with(
            '198.51.100.99',
            'web_auth',
            Mockery::type('string'),
            1800
        );

    $incidentService = new SecurityIncidentService($banService);

    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Attempt 1');
    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Attempt 2');
    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Attempt 3');
});

it('respects protect_web setting to disable web login tracking', function (): void {
    SecuritySetting::set('protect_web', false);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldNotReceive('ban');

    $incidentService = new SecurityIncidentService($banService);

    expect($incidentService->isVectorProtected('web_auth'))->toBeFalse();

    for ($i = 0; $i < 10; $i++) {
        $incidentService->recordFailure('198.51.100.77', 'web_auth', 'Attempt');
    }
});

/*
|--------------------------------------------------------------------------
| SIP Authentication Failure Listener Tests
|--------------------------------------------------------------------------
*/

it('captures sofia::failed_auth CustomEvent and forwards to SecurityIncidentService', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with(
            '198.51.100.77',
            'sip_auth',
            'SIP User: 1002, Realm: sip.tallpbx.com, Profile: internal'
        );

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new CustomEvent(
        eventName: 'CUSTOM',
        headers: [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'sofia::failed_auth',
            'network-ip' => '198.51.100.77',
            'user' => '1002',
            'realm' => 'sip.tallpbx.com',
            'profile-name' => 'internal',
        ],
        body: '',
    );

    event($event);
});

it('captures SofiaFailedAuth typed event and forwards to SecurityIncidentService', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with(
            '203.0.113.55',
            'sip_auth',
            'SIP User: admin, Realm: 192.168.1.1, Profile: external'
        );

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new SofiaFailedAuth(
        eventName: 'CUSTOM',
        headers: [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'sofia::failed_auth',
            'network-ip' => '203.0.113.55',
            'user' => 'admin',
            'realm' => '192.168.1.1',
            'profile-name' => 'external',
        ],
        body: '',
    );

    expect($event->networkIp())->toBe('203.0.113.55')
        ->and($event->user())->toBe('admin')
        ->and($event->realm())->toBe('192.168.1.1')
        ->and($event->profileName())->toBe('external');

    event($event);
});

it('ignores other FreeSWITCH custom events in sip auth listener', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldNotReceive('recordFailure');

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $event = new CustomEvent(
        eventName: 'CUSTOM',
        headers: [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'conference::maintenance',
            'Action' => 'add-member',
        ],
        body: '',
    );

    event($event);
});

it('handles FreeSwitchListenCommand ESL dispatch of sofia::failed_auth', function (): void {
    $mockIncident = Mockery::mock(SecurityIncidentServiceInterface::class);
    $mockIncident->shouldReceive('recordFailure')
        ->once()
        ->with(
            '185.220.101.5',
            'sip_auth',
            'SIP User: 1001, Realm: sip.tallpbx.com, Profile: internal'
        );

    $this->app->instance(SecurityIncidentServiceInterface::class, $mockIncident);

    $mockEsl = Mockery::mock(FreeSwitchServiceInterface::class);
    $mockEsl->shouldReceive('connect')->once()->andReturn(true);
    $mockEsl->shouldReceive('subscribeToEvents')->once();
    $mockEsl->shouldReceive('isConnected')->once()->andReturn(true);
    $mockEsl->shouldReceive('recvEvent')->once()->andReturn([
        'event_name' => 'CUSTOM',
        'headers' => [
            'Event-Name' => 'CUSTOM',
            'Event-Subclass' => 'sofia::failed_auth',
            'network-ip' => '185.220.101.5',
            'user' => '1001',
            'realm' => 'sip.tallpbx.com',
            'profile-name' => 'internal',
        ],
        'body' => '',
    ]);
    $mockEsl->shouldReceive('disconnect')->once();

    $this->app->instance(FreeSwitchServiceInterface::class, $mockEsl);

    $this->artisan('freeswitch:listen', ['--once' => true])
        ->assertSuccessful();
});

it('exempts whitelisted IP from SIP failure bans', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '64.2.142.18',
        'description' => 'Carrier SIP trunk endpoint',
    ]);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldNotReceive('ban');

    $incidentService = new SecurityIncidentService($banService);

    for ($i = 0; $i < 10; $i++) {
        $incidentService->recordFailure('64.2.142.18', 'sip_auth', 'Failed password attempt');
    }

    expect($incidentService->isWhitelisted('64.2.142.18'))->toBeTrue();
});

it('triggers ban when SIP auth failures exceed max_retry threshold', function (): void {
    SecuritySetting::set('max_retry', 4);
    SecuritySetting::set('find_time', 600);
    SecuritySetting::set('ban_time', 3600);

    $banService = Mockery::mock(SecurityBanServiceInterface::class);
    $banService->shouldReceive('ban')
        ->once()
        ->with(
            '185.220.101.99',
            'sip_auth',
            Mockery::type('string'),
            3600
        );

    $incidentService = new SecurityIncidentService($banService);

    for ($i = 1; $i <= 3; $i++) {
        $incidentService->recordFailure('185.220.101.99', 'sip_auth', "Attempt {$i}");
    }

    $incidentService->recordFailure('185.220.101.99', 'sip_auth', 'Attempt 4');
});

/*
|--------------------------------------------------------------------------
| SIP Scanner Event Listener Tests
|--------------------------------------------------------------------------
*/

it('exposes the scanner fields through the typed event accessors', function (): void {
    $event = scannerEvent('203.0.113.5', 'sipcli/v1.8', 'high', 'User-Agent');

    expect($event->attackerIp())->toBe('203.0.113.5')
        ->and($event->scannerType())->toBe('User-Agent')
        ->and($event->scannerValue())->toBe('sipcli/v1.8')
        ->and($event->confidence())->toBe('high');

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
    SecuritySetting::set('sip_scanner_enforcement_enabled', false);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.10', 'friendly-scanner', 'high', 'User-Agent'));

    $row = SecurityBan::where('ip_address', '203.0.113.10')->first();

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

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.14'));

    expect(SecurityBan::where('ip_address', '203.0.113.14')->count())->toBe(1)
        ->and(SecurityAuditLog::where('ip_address', '203.0.113.14')->count())->toBe(0);
});

it('keeps low-confidence signatures record-only without auto-banning to protect shared NAT environments', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);
    SecuritySetting::set('sip_scanner_ban_seconds', 86400);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.15', 'SIP Call', 'low'));
    event(scannerEvent('203.0.113.15', 'Generic Softphone', 'low'));

    $incident = SecurityBan::where('ip_address', '203.0.113.15')->first();
    expect($incident)->not->toBeNull()
        ->and($incident->is_active)->toBeFalse();
});

it('does not escalate a repeated identical low-confidence signature', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', true);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.16', 'SIP Call', 'low'));
    event(scannerEvent('203.0.113.16', 'SIP Call', 'low'));

    $incident = SecurityBan::where('ip_address', '203.0.113.16')->first();
    expect($incident)->not->toBeNull()
        ->and($incident->is_active)->toBeFalse()
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

    event(scannerEvent('203.0.113.18', str_repeat('a', 400), 'high'));

    $incident = SecurityBan::where('ip_address', '203.0.113.18')->first();

    expect($incident)->not->toBeNull()
        ->and($incident->is_active)->toBeFalse()
        ->and(mb_strlen($incident->reason))->toBeLessThanOrEqual(255);
});

it('writes only one audit entry when the same address floods detections', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', false);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.20', 'friendly-scanner', 'high'));
    event(scannerEvent('203.0.113.20', 'friendly-scanner', 'high'));

    expect(SecurityAuditLog::where('action', 'sip_scanner_detected')->where('ip_address', '203.0.113.20')->count())->toBe(1);

    $incident = SecurityBan::where('ip_address', '203.0.113.20')->first();

    expect($incident)->not->toBeNull()
        ->and($incident->attempt_count)->toBe(2);
});

it('keeps the audit throttle scoped per address', function (): void {
    SecuritySetting::set('sip_scanner_enforcement_enabled', false);

    $this->banService->shouldNotReceive('ban');

    event(scannerEvent('203.0.113.21', 'friendly-scanner', 'high'));
    event(scannerEvent('203.0.113.22', 'friendly-scanner', 'high'));

    expect(SecurityAuditLog::where('action', 'sip_scanner_detected')
        ->whereIn('ip_address', ['203.0.113.21', '203.0.113.22'])
        ->count())->toBe(2);
});
