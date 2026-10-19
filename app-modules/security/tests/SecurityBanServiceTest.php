<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Bans\SecurityBanService;
use Modules\Security\Services\Bans\SecurityIncidentService;

/**
 * Feature tests for SecurityBanService and host ban management.
 *
 * Verifies active and historical ban persistence in MariaDB, audit log creation,
 * kernel executor delegation, zero-lockout whitelist protection, expiration calculations,
 * and end-to-end integration with SecurityIncidentService.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
    SecurityBan::query()->delete();

    // NOTE: deliberately NO global Redis wipe here. Parallel Pest workers and
    // consecutive runs share one live Redis, so `keys('tallpbx:security:attempts:*')`
    // deletes concurrently running tests' in-flight sliding-window counters and
    // silently suppresses their threshold bans. Counter-dependent tests instead
    // use per-run-unique IPs; leftover keys expire via their find_time TTLs.
});

it('resolves SecurityBanServiceInterface from service container', function (): void {
    $service = app(SecurityBanServiceInterface::class);

    expect($service)->toBeInstanceOf(SecurityBanService::class);
});

it('creates an active ban record in database and security audit log', function (): void {
    $banService = new SecurityBanService;

    $ban = $banService->ban(
        ip: '203.0.113.88',
        vector: 'web_auth',
        reason: 'Repeated invalid password attempts',
        durationSeconds: 3600,
    );

    expect($ban)->toBeInstanceOf(SecurityBan::class)
        ->and($ban->ip_address)->toBe('203.0.113.88')
        ->and($ban->vector)->toBe('web_auth')
        ->and($ban->reason)->toBe('Repeated invalid password attempts')
        ->and($ban->attempt_count)->toBe(1)
        ->and($ban->is_active)->toBeTrue()
        ->and($ban->expires_at)->not->toBeNull()
        ->and($ban->isExpired())->toBeFalse();

    // Verify record in database
    $this->assertDatabaseHas('security_bans', [
        'ip_address' => '203.0.113.88',
        'vector' => 'web_auth',
        'is_active' => true,
    ]);

    // Verify audit log record
    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'ban_created',
        'ip_address' => '203.0.113.88',
    ]);

    expect($banService->isBanned('203.0.113.88'))->toBeTrue();
});

it('increments attempt count and updates expiration when banning an already active IP', function (): void {
    $banService = new SecurityBanService;

    $firstBan = $banService->ban(
        ip: '198.51.100.42',
        vector: 'sip_auth',
        reason: 'Initial SIP attack',
        durationSeconds: 1800,
    );

    expect($firstBan->attempt_count)->toBe(1);

    // Second ban while still active
    $secondBan = $banService->ban(
        ip: '198.51.100.42',
        vector: 'sip_auth',
        reason: 'Escalated SIP attack',
        durationSeconds: 7200,
    );

    expect($secondBan->id)->toBe($firstBan->id)
        ->and($secondBan->attempt_count)->toBe(2)
        ->and($secondBan->reason)->toBe('Escalated SIP attack')
        ->and($secondBan->is_active)->toBeTrue();

    expect(SecurityBan::where('ip_address', '198.51.100.42')->count())->toBe(1);
});

it('delegates ban execution to SecurityExecutorInterface when provided', function (): void {
    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('ban')
        ->once()
        ->with('192.0.2.77', 3600)
        ->andReturn(true);
    $mockExecutor->shouldReceive('flushConntrack')->andReturn(true);

    $banService = new SecurityBanService($mockExecutor);

    $ban = $banService->ban(
        ip: '192.0.2.77',
        vector: 'ssh',
        reason: 'SSH brute force',
        durationSeconds: 3600,
    );

    expect($ban->is_active)->toBeTrue();

    // The stateful fast path keeps an attacker's live sessions flowing until
    // its conntrack entries are flushed, so a successful kernel ban must also
    // sever those sessions (invariant 4).
    $mockExecutor->shouldHaveReceived('flushConntrack')->once()->with('192.0.2.77');
});

it('does not flush conntrack entries when the kernel ban fails', function (): void {
    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('ban')->once()->andReturn(false);
    $mockExecutor->shouldReceive('flushConntrack')->andReturn(true);

    $banService = new SecurityBanService($mockExecutor);

    $banService->ban('192.0.2.78', 'ssh', 'Executor failure path', 3600);

    // Nothing was blocked in the kernel, so severing sessions would cut off
    // an address that is not actually banned.
    $mockExecutor->shouldNotHaveReceived('flushConntrack');
});

it('records a visible audit entry when the conntrack flush fails', function (): void {
    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('ban')->once()->andReturn(true);
    $mockExecutor->shouldReceive('flushConntrack')->once()->andReturn(false);

    $banService = new SecurityBanService($mockExecutor);

    $banService->ban('192.0.2.79', 'sip_auth', 'Flush failure path', 3600);

    // A flush failure is not rolled back (the new-flow block stays active),
    // but it must be visible to operators — not buried in laravel.log.
    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'conntrack_flush_failed',
        'ip_address' => '192.0.2.79',
    ]);
});

it('unbans an IP, marks active records inactive, and records audit trail', function (): void {
    $banService = new SecurityBanService;

    $banService->ban(
        ip: '203.0.113.99',
        vector: 'web_auth',
        reason: 'Test ban',
        durationSeconds: 3600,
    );

    expect($banService->isBanned('203.0.113.99'))->toBeTrue();

    $admin = Admin::factory()->create();
    $result = $banService->unban('203.0.113.99', adminId: $admin->id);

    expect($result)->toBeTrue()
        ->and($banService->isBanned('203.0.113.99'))->toBeFalse();

    $this->assertDatabaseHas('security_bans', [
        'ip_address' => '203.0.113.99',
        'is_active' => false,
        'unbanned_by_admin_id' => $admin->id,
    ]);

    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'unban_executed',
        'ip_address' => '203.0.113.99',
        'admin_id' => $admin->id,
    ]);
});

it('delegates unban execution to SecurityExecutorInterface when provided', function (): void {
    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('unban')
        ->once()
        ->with('198.51.100.55')
        ->andReturn(true);

    $banService = new SecurityBanService($mockExecutor);

    $banService->ban('198.51.100.55', 'manual', 'Manual test ban');
    $banService->unban('198.51.100.55');
});

it('refuses to ban a whitelisted IP and throws InvalidArgumentException', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '192.168.1.100',
        'description' => 'Office PBX management subnet',
    ]);

    $banService = new SecurityBanService;

    expect(fn () => $banService->ban(
        ip: '192.168.1.100',
        vector: 'web_auth',
        reason: 'Accidental password misentry',
    ))->toThrow(\InvalidArgumentException::class, 'Cannot ban whitelisted IP address: 192.168.1.100');

    expect(SecurityBan::where('ip_address', '192.168.1.100')->exists())->toBeFalse();
});

it('refuses to ban an IP covered by a whitelisted CIDR subnet', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '10.50.0.0/16',
        'description' => 'Corporate VPN range',
    ]);

    $banService = new SecurityBanService;

    expect(fn () => $banService->ban(
        ip: '10.50.12.34',
        vector: 'sip_auth',
        reason: 'Failed registration from VPN softphone',
    ))->toThrow(\InvalidArgumentException::class, 'Cannot ban whitelisted IP address: 10.50.12.34');
});

it('accepts IPv6 bans while still refusing malformed addresses', function (): void {
    $banService = new SecurityBanService;

    // IPv6 bans are fully supported by the dual-stack kernel pipeline.
    $ban = $banService->ban(
        ip: '2001:db8::42',
        vector: 'manual',
        reason: 'IPv6 brute force test',
        durationSeconds: 3600,
    );

    expect($ban->ip_address)->toBe('2001:db8::42')
        ->and($ban->is_active)->toBeTrue();

    // Malformed addresses (invalid octets, broken IPv6) are refused outright.
    expect(fn () => $banService->ban(
        ip: '344.34.34.34',
        vector: 'manual',
        reason: 'Invalid octet test',
    ))->toThrow(\InvalidArgumentException::class, 'Invalid ban address');

    expect(SecurityBan::where('ip_address', '344.34.34.34')->exists())->toBeFalse();
});

it('creates a permanent ban when durationSeconds is null or 0', function (): void {
    $banService = new SecurityBanService;

    $ban = $banService->ban(
        ip: '203.0.113.200',
        vector: 'manual',
        reason: 'Permanent administrative block',
        durationSeconds: null,
    );

    expect($ban->expires_at)->toBeNull()
        ->and($ban->isExpired())->toBeFalse()
        ->and($ban->timeRemaining())->toBeNull()
        ->and($banService->isBanned('203.0.113.200'))->toBeTrue();
});

it('retrieves active bans excluding expired bans via getActiveBans', function (): void {
    $banService = new SecurityBanService;

    // Active temporary ban (expires in 1 hour)
    $banService->ban('203.0.113.1', 'web_auth', 'Active temp', 3600);

    // Active permanent ban
    $banService->ban('203.0.113.2', 'manual', 'Active perm', null);

    // Manually create an expired ban in the database
    SecurityBan::create([
        'ip_address' => '203.0.113.3',
        'vector' => 'sip_auth',
        'reason' => 'Old expired ban',
        'attempt_count' => 1,
        'banned_at' => Carbon::now()->subHours(2),
        'expires_at' => Carbon::now()->subHour(),
        'is_active' => true,
    ]);

    $activeBans = $banService->getActiveBans();

    expect($activeBans)->toHaveCount(2)
        ->and($activeBans->pluck('ip_address')->all())->toContain('203.0.113.1', '203.0.113.2')
        ->and($activeBans->pluck('ip_address')->all())->not->toContain('203.0.113.3');

    expect($banService->isBanned('203.0.113.3'))->toBeFalse();
});

it('prunes expired bans by marking them inactive in the database', function (): void {
    $banService = new SecurityBanService;

    SecurityBan::create([
        'ip_address' => '203.0.113.4',
        'vector' => 'web_auth',
        'reason' => 'Expired ban awaiting cleanup',
        'attempt_count' => 1,
        'banned_at' => Carbon::now()->subHours(3),
        'expires_at' => Carbon::now()->subHours(2),
        'is_active' => true,
    ]);

    $pruned = $banService->pruneExpiredBans();

    expect($pruned)->toBe(1);

    $this->assertDatabaseHas('security_bans', [
        'ip_address' => '203.0.113.4',
        'is_active' => false,
    ]);
});

it('integrates SecurityIncidentService with SecurityBanService end-to-end', function (): void {
    // Unique IP per run: parallel workers and consecutive runs share one live
    // Redis, so a fixed address lets another test's cleanup (or leftovers from
    // a previous run) reset this counter mid-threshold.
    $ip = '198.51.'.random_int(0, 254).'.'.random_int(2, 254);

    SecuritySetting::set('max_retry', 3);
    SecuritySetting::set('find_time', 300);
    SecuritySetting::set('ban_time', 7200);

    $banService = new SecurityBanService;
    $incidentService = new SecurityIncidentService($banService);

    expect($banService->isBanned($ip))->toBeFalse();

    // First two failures do not ban
    $incidentService->recordFailure($ip, 'web_auth', 'Failed login attempt 1');
    $incidentService->recordFailure($ip, 'web_auth', 'Failed login attempt 2');
    expect($banService->isBanned($ip))->toBeFalse();

    // Third failure reaches max_retry and triggers automatic ban
    $incidentService->recordFailure($ip, 'web_auth', 'Failed login attempt 3');

    expect($banService->isBanned($ip))->toBeTrue();

    $this->assertDatabaseHas('security_bans', [
        'ip_address' => $ip,
        'vector' => 'web_auth',
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'ban_created',
        'ip_address' => $ip,
    ]);
});

it('handles a flood of failed attempts across hundreds of distinct IPs without leakage', function (): void {
    // Unique subnet per run: parallel workers and consecutive runs share one
    // live Redis, so a fixed range lets leftover sliding-window counters (or a
    // concurrently running test) reset this flood mid-threshold.
    $subnet = random_int(1, 254);

    SecuritySetting::set('max_retry', 3);
    SecuritySetting::set('find_time', 300);
    SecuritySetting::set('ban_time', 3600);

    $banService = new SecurityBanService;
    $incidentService = new SecurityIncidentService($banService);

    // Simulate 100 distinct attacking IPs each with 2 failures (under threshold: counter stays in Redis)
    for ($i = 1; $i <= 100; $i++) {
        $ip = "192.0.{$subnet}.{$i}";
        $incidentService->recordFailure($ip, 'sip_auth', 'Failed attempt 1');
        $incidentService->recordFailure($ip, 'sip_auth', 'Failed attempt 2');
        expect($banService->isBanned($ip))->toBeFalse();
    }

    // Simulate 20 distinct IPs that reach threshold and get banned (purged from Redis, stored in DB)
    for ($i = 101; $i <= 120; $i++) {
        $ip = "192.0.{$subnet}.{$i}";
        $incidentService->recordFailure($ip, 'sip_auth', 'Failed attempt 1');
        $incidentService->recordFailure($ip, 'sip_auth', 'Failed attempt 2');
        $incidentService->recordFailure($ip, 'sip_auth', 'Failed attempt 3');
        expect($banService->isBanned($ip))->toBeTrue();
    }

    // Active bans count in database matches the 20 banned IPs
    expect(SecurityBan::active()->where('vector', 'sip_auth')->count())->toBe(20);

    // Ensure banned IPs had their Redis attempt keys purged
    $redisAvailable = true;
    try {
        Redis::ping();
    } catch (\Throwable) {
        $redisAvailable = false;
    }

    if ($redisAvailable) {
        for ($i = 101; $i <= 120; $i++) {
            $key = "tallpbx:security:attempts:sip_auth:192.0.{$subnet}.{$i}";
            expect(Redis::get($key))->toBeNull();
        }
    }
});
