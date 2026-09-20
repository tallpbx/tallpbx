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
use Modules\Security\Services\SecurityBanService;
use Modules\Security\Services\SecurityIncidentService;

/**
 * Feature tests for SecurityBanService and host ban management.
 *
 * Verifies active and historical ban persistence in MariaDB, audit log creation,
 * kernel executor delegation, zero-lockout whitelist protection, expiration calculations,
 * and end-to-end integration with SecurityIncidentService.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);

    // Flush test redis keys if available
    try {
        $keys = Redis::keys('tallpbx:security:attempts:*');
        if (! empty($keys)) {
            Redis::del($keys);
        }
    } catch (\Throwable) {
        // Ignore in environments without live Redis
    }
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

    $banService = new SecurityBanService($mockExecutor);

    $ban = $banService->ban(
        ip: '192.0.2.77',
        vector: 'ssh',
        reason: 'SSH brute force',
        durationSeconds: 3600,
    );

    expect($ban->is_active)->toBeTrue();
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

it('refuses to ban invalid or IPv6 addresses while the kernel pipeline is IPv4-only', function (): void {
    $banService = new SecurityBanService;

    // Octets above 255 can never be enforced and used to corrupt generated rulesets.
    expect(fn () => $banService->ban(
        ip: '344.34.34.34',
        vector: 'manual',
        reason: 'Invalid octet test',
    ))->toThrow(\InvalidArgumentException::class, 'Unsupported ban address');

    // IPv6 bans are deferred until dual-stack kernel support ships.
    expect(fn () => $banService->ban(
        ip: '2001:569:fcd9:900:e95c:2439:5a28:86b',
        vector: 'manual',
        reason: 'IPv6 interim refusal test',
    ))->toThrow(\InvalidArgumentException::class, 'Unsupported ban address');

    expect(SecurityBan::where('ip_address', '344.34.34.34')->exists())->toBeFalse()
        ->and(SecurityBan::where('ip_address', '2001:569:fcd9:900:e95c:2439:5a28:86b')->exists())->toBeFalse();
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
    SecuritySetting::set('max_retry', 3);
    SecuritySetting::set('find_time', 300);
    SecuritySetting::set('ban_time', 7200);

    $banService = new SecurityBanService;
    $incidentService = new SecurityIncidentService($banService);

    expect($banService->isBanned('198.51.100.99'))->toBeFalse();

    // First two failures do not ban
    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Failed login attempt 1');
    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Failed login attempt 2');
    expect($banService->isBanned('198.51.100.99'))->toBeFalse();

    // Third failure reaches max_retry and triggers automatic ban
    $incidentService->recordFailure('198.51.100.99', 'web_auth', 'Failed login attempt 3');

    expect($banService->isBanned('198.51.100.99'))->toBeTrue();

    $this->assertDatabaseHas('security_bans', [
        'ip_address' => '198.51.100.99',
        'vector' => 'web_auth',
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'ban_created',
        'ip_address' => '198.51.100.99',
    ]);
});
