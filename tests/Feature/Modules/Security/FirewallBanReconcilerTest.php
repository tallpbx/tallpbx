<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Carbon;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityAuditLog;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Services\FirewallBanReconciler;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Services\SecurityConfigGenerator;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->seed(SecurityServiceSeeder::class);

    $this->executor = Mockery::mock(SecurityExecutorInterface::class);
    $this->app->instance(SecurityExecutorInterface::class, $this->executor);

    $this->lockoutGuard = app(LockoutGuardService::class);
    $this->generator = Mockery::mock(SecurityConfigGenerator::class);
    $this->app->instance(SecurityConfigGenerator::class, $this->generator);
});

it('restores missing active DB bans in kernel with remaining TTL', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));

    SecurityBan::create([
        'ip_address' => '198.51.100.55',
        'vector' => 'web_auth',
        'reason' => 'Test missing ban',
        'attempt_count' => 5,
        'banned_at' => now()->subMinutes(10),
        'expires_at' => now()->addMinutes(50), // 3000 seconds remaining
        'is_active' => true,
    ]);

    $this->executor->shouldReceive('status')->andReturn("table inet tallpbx_filter {\n}");
    $this->executor->shouldReceive('bans')->once()->andReturn([]);
    $this->executor->shouldReceive('ban')->once()->with('198.51.100.55', 3000)->andReturnTrue();

    $reconciler = new FirewallBanReconciler($this->executor, $this->lockoutGuard, $this->generator);
    $result = $reconciler->reconcile();

    expect($result['added'])->toHaveKey('198.51.100.55', 3000)
        ->and($result['removed'])->toBeEmpty()
        ->and($result['errors'])->toBeEmpty();

    expect(SecurityAuditLog::where('action', 'ban_reconciled_added')->where('ip_address', '198.51.100.55')->exists())->toBeTrue();
});

it('removes extraneous kernel bans that are not active in database', function (): void {
    $this->executor->shouldReceive('status')->andReturn("table inet tallpbx_filter {\n}");
    $this->executor->shouldReceive('bans')->once()->andReturn([
        '203.0.113.88' => [
            'ip' => '203.0.113.88',
            'timeout' => 3600,
            'expires' => 1800,
            'family' => 'ipv4',
        ],
    ]);
    $this->executor->shouldReceive('unban')->once()->with('203.0.113.88')->andReturnTrue();

    $reconciler = new FirewallBanReconciler($this->executor, $this->lockoutGuard, $this->generator);
    $result = $reconciler->reconcile();

    expect($result['removed'])->toContain('203.0.113.88')
        ->and($result['added'])->toBeEmpty();

    expect(SecurityAuditLog::where('action', 'ban_reconciled_removed')->where('ip_address', '203.0.113.88')->exists())->toBeTrue();
});

it('re-times bans when TTL skew exceeds threshold', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));

    SecurityBan::create([
        'ip_address' => '198.51.100.77',
        'vector' => 'sip_auth',
        'reason' => 'Test skew ban',
        'attempt_count' => 3,
        'banned_at' => now()->subMinutes(10),
        'expires_at' => now()->addMinutes(50), // 3000 seconds remaining
        'is_active' => true,
    ]);

    $this->executor->shouldReceive('status')->andReturn("table inet tallpbx_filter {\n}");
    $this->executor->shouldReceive('bans')->once()->andReturn([
        '198.51.100.77' => [
            'ip' => '198.51.100.77',
            'timeout' => 3600,
            'expires' => 1000, // Skew is 2000s > 300s threshold
            'family' => 'ipv4',
        ],
    ]);
    $this->executor->shouldReceive('ban')->once()->with('198.51.100.77', 3000)->andReturnTrue();

    $reconciler = new FirewallBanReconciler($this->executor, $this->lockoutGuard, $this->generator);
    $result = $reconciler->reconcile();

    expect($result['retimed'])->toHaveKey('198.51.100.77', 3000);
    expect(SecurityAuditLog::where('action', 'ban_reconciled_retimed')->where('ip_address', '198.51.100.77')->exists())->toBeTrue();
});

it('skips bans with less than MIN_TTL_THRESHOLD remaining', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));

    SecurityBan::create([
        'ip_address' => '198.51.100.12',
        'vector' => 'web_auth',
        'reason' => 'Expiring soon',
        'attempt_count' => 2,
        'banned_at' => now()->subHour(),
        'expires_at' => now()->addSeconds(15), // 15 seconds remaining < 30s threshold
        'is_active' => true,
    ]);

    $this->executor->shouldReceive('status')->andReturn("table inet tallpbx_filter {\n}");
    $this->executor->shouldReceive('bans')->once()->andReturn([]);
    $this->executor->shouldNotReceive('ban');

    $reconciler = new FirewallBanReconciler($this->executor, $this->lockoutGuard, $this->generator);
    $result = $reconciler->reconcile();

    expect($result['skipped'])->toContain('198.51.100.12')
        ->and($result['added'])->toBeEmpty();
});

it('obeys dryRun mode without executing mutations', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));

    SecurityBan::create([
        'ip_address' => '198.51.100.99',
        'vector' => 'manual',
        'reason' => 'Dry run test',
        'attempt_count' => 1,
        'banned_at' => now(),
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->executor->shouldReceive('status')->andReturn("table inet tallpbx_filter {\n}");
    $this->executor->shouldReceive('bans')->once()->andReturn([
        '203.0.113.5' => [
            'ip' => '203.0.113.5',
            'timeout' => 3600,
            'expires' => 1800,
            'family' => 'ipv4',
        ],
    ]);

    $this->executor->shouldNotReceive('ban');
    $this->executor->shouldNotReceive('unban');

    $reconciler = new FirewallBanReconciler($this->executor, $this->lockoutGuard, $this->generator);
    $result = $reconciler->reconcile(dryRun: true);

    expect($result['added'])->toHaveKey('198.51.100.99', 3600)
        ->and($result['removed'])->toContain('203.0.113.5');

    expect(SecurityAuditLog::count())->toBe(0);
});

it('performs boot recovery when kernel table is absent', function (): void {
    $this->executor->shouldReceive('status')->andReturn("table inet other_filter {\n}");
    $this->generator->shouldReceive('writePending')->once()->andReturn('/etc/tallpbx/firewall.nft.pending');
    $this->generator->shouldReceive('validateSyntax')->once()->with('/etc/tallpbx/firewall.nft.pending')->andReturnTrue();
    $this->executor->shouldReceive('apply')->once()->andReturnTrue();
    $this->executor->shouldReceive('bans')->once()->andReturn([]);

    $reconciler = new FirewallBanReconciler($this->executor, $this->lockoutGuard, $this->generator);
    $result = $reconciler->reconcile();

    expect($result['boot_recovered'])->toBeTrue();
    expect(SecurityAuditLog::where('action', 'firewall_boot_recovered')->exists())->toBeTrue();
});

it('aborts boot recovery if syntax validation fails', function (): void {
    $this->executor->shouldReceive('status')->andReturn("table inet other_filter {\n}");
    $this->generator->shouldReceive('writePending')->once()->andReturn('/etc/tallpbx/firewall.nft.pending');
    $this->generator->shouldReceive('validateSyntax')->once()->with('/etc/tallpbx/firewall.nft.pending')->andReturnFalse();
    $this->executor->shouldNotReceive('apply');

    $reconciler = new FirewallBanReconciler($this->executor, $this->lockoutGuard, $this->generator);
    $result = $reconciler->reconcile();

    expect($result['boot_recovered'])->toBeFalse()
        ->and($result['errors'])->toContain('Boot recovery aborted: pending ruleset failed syntax check.');
});

it('enforces MAX_ACTIONS limit to prevent flap loops', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));

    for ($i = 1; $i <= 55; $i++) {
        SecurityBan::create([
            'ip_address' => "198.51.100.{$i}",
            'vector' => 'web_auth',
            'reason' => "Batch ban {$i}",
            'attempt_count' => 1,
            'banned_at' => now(),
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
    }

    $this->executor->shouldReceive('status')->andReturn("table inet tallpbx_filter {\n}");
    $this->executor->shouldReceive('bans')->once()->andReturn([]);
    $this->executor->shouldReceive('ban')->times(50)->andReturnTrue();

    $reconciler = new FirewallBanReconciler($this->executor, $this->lockoutGuard, $this->generator);
    $result = $reconciler->reconcile();

    expect(count($result['added']))->toBe(50)
        ->and(count($result['skipped']))->toBe(5);
});
