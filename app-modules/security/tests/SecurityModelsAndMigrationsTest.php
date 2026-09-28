<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Database\QueryException;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;

/**
 * Feature tests for Security module database migrations, Eloquent models, and seeders.
 *
 * Verifies schema integrity, table relationships, model scopes, custom helpers,
 * and standard PBX port catalog seed data.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

it('seeds standard PBX services into security_services', function (): void {
    expect(SecurityService::count())->toBe(8);

    $icmp = SecurityService::where('name', 'ICMP Ping Diagnostics')->first();
    expect($icmp)->not->toBeNull()
        ->and($icmp->protocol)->toBe('icmp')
        ->and($icmp->is_system)->toBeTrue();

    $sip = SecurityService::where('name', 'SIP Signaling')->first();
    expect($sip)->not->toBeNull()
        ->and($sip->protocol)->toBe('both')
        ->and($sip->port_range)->toBe('5060,5061,5080')
        ->and($sip->is_system)->toBeTrue();

    $rtp = SecurityService::where('name', 'RTP Voice/Video Media')->first();
    expect($rtp)->not->toBeNull()
        ->and($rtp->protocol)->toBe('udp')
        ->and($rtp->port_range)->toBe('16384-32768')
        ->and($rtp->is_system)->toBeTrue();

    $web = SecurityService::where('name', 'Web Admin Portal')->first();
    expect($web)->not->toBeNull()
        ->and($web->protocol)->toBe('tcp')
        ->and($web->port_range)->toBe('80,443');

    $ssh = SecurityService::where('name', 'SSH Console')->first();
    expect($ssh)->not->toBeNull()
        ->and($ssh->port_range)->toBe('22');
});

it('seeds default security settings into security_settings', function (): void {
    expect(SecuritySetting::getBoolean('firewall_enabled'))->toBeTrue()
        ->and(SecuritySetting::get('firewall_default_policy'))->toBe('drop')
        ->and(SecuritySetting::getBoolean('intrusion_detection_enabled'))->toBeTrue()
        ->and(SecuritySetting::getInt('ban_time'))->toBe(3600)
        ->and(SecuritySetting::getInt('find_time'))->toBe(600)
        ->and(SecuritySetting::getInt('max_retry'))->toBe(5)
        ->and(SecuritySetting::getBoolean('protect_sip'))->toBeTrue()
        ->and(SecuritySetting::getBoolean('protect_web'))->toBeTrue()
        ->and(SecuritySetting::getBoolean('protect_ssh'))->toBeTrue();
});

it('supports SecurityService model scopes and relations', function (): void {
    // System service scope
    expect(SecurityService::system()->count())->toBe(8)
        ->and(SecurityService::custom()->count())->toBe(0);

    // Create a custom service
    $custom = SecurityService::create([
        'name' => 'Custom Billing API',
        'description' => 'Accounting software webhook listener',
        'protocol' => 'tcp',
        'port_range' => '9000',
        'is_system' => false,
    ]);

    expect(SecurityService::custom()->count())->toBe(1)
        ->and($custom->is_system)->toBeFalse();

    // Create a rule linked to this service
    $rule = SecurityRule::create([
        'sequence' => 10,
        'description' => 'Allow Billing API from office',
        'source_ip' => '192.168.50.0/24',
        'service_id' => $custom->id,
        'action' => 'accept',
        'enabled' => true,
    ]);

    expect($custom->rules)->toHaveCount(1)
        ->and($rule->service->id)->toBe($custom->id);
});

it('manages sequential firewall rules with ordering and active scopes', function (): void {
    SecurityRule::create([
        'sequence' => 50,
        'description' => 'Rule 50',
        'source_ip' => 'any',
        'action' => 'accept',
        'enabled' => false,
    ]);

    SecurityRule::create([
        'sequence' => 10,
        'description' => 'Rule 10',
        'source_ip' => '10.0.0.0/8',
        'action' => 'accept',
        'enabled' => true,
    ]);

    SecurityRule::create([
        'sequence' => 20,
        'description' => 'Rule 20',
        'source_ip' => '192.168.1.100',
        'action' => 'drop',
        'enabled' => true,
    ]);

    $ordered = SecurityRule::ordered()->get();
    expect($ordered[0]->sequence)->toBe(10)
        ->and($ordered[1]->sequence)->toBe(20)
        ->and($ordered[2]->sequence)->toBe(50);

    $active = SecurityRule::active()->get();
    expect($active)->toHaveCount(2);
});

it('manages trusted and blocked IP lists and enforces uniqueness', function (): void {
    $whitelist = SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '198.51.100.10',
        'description' => 'HQ Static IP',
    ]);

    $blacklist = SecurityIpList::create([
        'type' => 'blacklist',
        'ip_address' => '203.0.113.99',
        'description' => 'Known SIP scanner',
    ]);

    expect($whitelist->isWhitelist())->toBeTrue()
        ->and($whitelist->isBlacklist())->toBeFalse()
        ->and($blacklist->isBlacklist())->toBeTrue()
        ->and(SecurityIpList::whitelist()->count())->toBe(1)
        ->and(SecurityIpList::blacklist()->count())->toBe(1);

    // Duplicate type + ip_address violates unique index
    expect(fn () => SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '198.51.100.10',
    ]))->toThrow(QueryException::class);
});

it('manages key-value security settings with helper methods', function (): void {
    SecuritySetting::set('custom_banner_text', 'Welcome to TallPBX');
    expect(SecuritySetting::get('custom_banner_text'))->toBe('Welcome to TallPBX');

    SecuritySetting::set('rate_limit_active', true);
    expect(SecuritySetting::getBoolean('rate_limit_active'))->toBeTrue();

    SecuritySetting::set('max_attempts', 15);
    expect(SecuritySetting::getInt('max_attempts'))->toBe(15);

    // Overwrite existing key
    SecuritySetting::set('max_attempts', 20);
    expect(SecuritySetting::getInt('max_attempts'))->toBe(20);
});

it('tracks active and expired bans with timeRemaining helper', function (): void {
    $activeBan = SecurityBan::create([
        'ip_address' => '185.220.101.5',
        'vector' => 'sip_auth',
        'reason' => 'Repeated failed SIP registrations',
        'attempt_count' => 5,
        'banned_at' => now(),
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $permanentBan = SecurityBan::create([
        'ip_address' => '45.142.120.25',
        'vector' => 'ssh',
        'reason' => 'Permanent SSH brute force ban',
        'attempt_count' => 10,
        'banned_at' => now(),
        'expires_at' => null,
        'is_active' => true,
    ]);

    $expiredBan = SecurityBan::create([
        'ip_address' => '198.51.100.44',
        'vector' => 'web_auth',
        'reason' => 'Old web login failure',
        'attempt_count' => 3,
        'banned_at' => now()->subHours(2),
        'expires_at' => now()->subHour(),
        'is_active' => true,
    ]);

    expect($activeBan->isExpired())->toBeFalse()
        ->and($activeBan->timeRemaining())->toBeGreaterThan(3500)
        ->and($permanentBan->isExpired())->toBeFalse()
        ->and($permanentBan->timeRemaining())->toBeNull()
        ->and($expiredBan->isExpired())->toBeTrue()
        ->and($expiredBan->timeRemaining())->toBe(0);

    // Active scope only returns non-expired active bans
    $activeBans = SecurityBan::active()->get();
    expect($activeBans->pluck('ip_address'))->toContain('185.220.101.5', '45.142.120.25')
        ->and($activeBans->pluck('ip_address'))->not->toContain('198.51.100.44');
});

it('records security audit logs with admin association and details casting', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);

    $log = SecurityAuditLog::record(
        action: 'unban_executed',
        ipAddress: '185.220.101.5',
        description: 'Administrator manually lifted ban',
        details: ['reason' => 'Legitimate customer IP resolved'],
        adminId: $admin->id,
    );

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('unban_executed')
        ->and($log->ip_address)->toBe('185.220.101.5')
        ->and($log->admin->id)->toBe($admin->id)
        ->and($log->details)->toBe(['reason' => 'Legitimate customer IP resolved']);
});

it('standardizes colon port ranges to hyphens', function (): void {
    $service = SecurityService::create([
        'name' => 'Legacy Service',
        'description' => 'Legacy port range with colon',
        'protocol' => 'udp',
        'port_range' => '20000:30000',
        'is_system' => false,
    ]);

    $rule = SecurityRule::create([
        'sequence' => 999,
        'description' => 'Legacy Rule',
        'source_ip' => 'any',
        'custom_port' => '10000:15000',
        'custom_protocol' => 'tcp',
        'action' => 'accept',
        'enabled' => true,
    ]);

    $migration = require __DIR__.'/../../../../app-modules/security/database/migrations/2026_09_20_000009_standardize_security_port_ranges_to_hyphens.php';
    $migration->up();

    expect($service->fresh()->port_range)->toBe('20000-30000')
        ->and($rule->fresh()->custom_port)->toBe('10000-15000');
});
