<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Firewall\FirewallBanReconciler;
use Modules\Security\Services\Firewall\FirewallSyncVerifier;
use Modules\Security\Services\Firewall\LockoutGuardService;
use Modules\Security\Services\Firewall\SecurityConfigGenerator;
use Modules\Security\Services\Geo\GeoBlockingService;
use Modules\Security\Support\FirewallSyncStatus;

/**
 * Feature tests for Security console commands (security:apply, security:status, security:unban).
 *
 * Verifies CLI argument handling, zero-lockout protection checks, audit logging,
 * ban status display, and IP unbanning workflows.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);

    // Keep privileged host operations out of the test suite: the CLI tests must
    // never write the pending ruleset into /etc/tallpbx or execute the real
    // bounded helper on the machine running the tests.
    $generator = Mockery::mock(SecurityConfigGenerator::class);
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);

    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('ban')->andReturn(true);
    $executor->shouldReceive('unban')->andReturn(true);
    $executor->shouldReceive('apply')->andReturn(true);
    $executor->shouldReceive('status')->andReturn('');
    $this->app->instance(SecurityExecutorInterface::class, $executor);
});

it('executes security:apply successfully and records audit log', function (): void {
    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('apply')->once()->andReturn(true);

    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:apply')
        ->expectsOutputToContain('SUCCESS: Host firewall ruleset applied atomically.')
        ->assertSuccessful();

    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'firewall_applied_cli',
    ]);
});

it('fails security:apply when executor returns false', function (): void {
    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('apply')->once()->andReturn(false);

    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:apply')
        ->expectsOutputToContain('Failed to apply firewall ruleset via bounded helper.')
        ->assertFailed();
});

it('blocks security:apply when lockout check fails without --force', function (): void {
    $mockGuard = Mockery::mock(LockoutGuardService::class);
    $mockGuard->shouldReceive('assertSafe')
        ->with('127.0.0.1')
        ->once()
        ->andThrow(new LockoutException('Zero-Lockout Safety Alert: Lockout detected.'));

    $this->app->instance(LockoutGuardService::class, $mockGuard);

    $this->artisan('security:apply')
        ->expectsOutputToContain('Zero-Lockout Safety Alert: Lockout detected.')
        ->assertFailed();
});

it('bypasses lockout safety check when --force is passed to security:apply', function (): void {
    $mockGuard = Mockery::mock(LockoutGuardService::class);
    $mockGuard->shouldNotReceive('assertSafe');
    $mockGuard->shouldNotReceive('assertLocalServicesSafe');

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('apply')->once()->andReturn(true);

    $this->app->instance(LockoutGuardService::class, $mockGuard);
    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:apply', ['--force' => true])
        ->assertSuccessful();
});

it('blocks security:apply when the stored settings would drop local service traffic', function (): void {
    // Pre-filter off plus the seeded blocking policy is exactly the
    // combination that severed the server's own loopback connections.
    SecuritySetting::set('prefilter_enabled', false);

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldNotReceive('apply');
    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:apply')
        ->expectsOutputToContain('Local service safety alert')
        ->assertFailed();
});

it('applies the ruleset when the pre-filter is off but the default policy allows', function (): void {
    SecuritySetting::set('prefilter_enabled', false);
    SecuritySetting::set('firewall_default_policy', 'accept');

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('apply')->once()->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:apply')
        ->expectsOutputToContain('SUCCESS: Host firewall ruleset applied atomically.')
        ->assertSuccessful();
});

it('bypasses the local service safety check when --force is passed', function (): void {
    SecuritySetting::set('prefilter_enabled', false);

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('apply')->once()->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:apply', ['--force' => true])
        ->assertSuccessful();
});

it('executes security:status displaying settings, active bans, and kernel output', function (): void {
    $banService = app(SecurityBanServiceInterface::class);
    $banService->ban(
        ip: '203.0.113.88',
        vector: 'web_auth',
        reason: 'Failed login attempts',
        durationSeconds: 3600,
    );

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('status')
        ->once()
        ->andReturn("table inet tallpbx_filter {\n    chain input { ... }\n}");

    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:status')
        ->expectsOutputToContain('TallPBX Security Engine Status')
        ->expectsOutputToContain('203.0.113.88')
        ->expectsOutputToContain('table inet tallpbx_filter')
        ->assertSuccessful();
});

it('executes security:unban lifting an active ban', function (): void {
    $banService = app(SecurityBanServiceInterface::class);
    $banService->ban(
        ip: '198.51.100.77',
        vector: 'sip_auth',
        reason: 'SIP attack',
        durationSeconds: 3600,
    );

    expect($banService->isBanned('198.51.100.77'))->toBeTrue();

    $this->artisan('security:unban', ['ip' => '198.51.100.77'])
        ->expectsOutputToContain('SUCCESS: Successfully unbanned 198.51.100.77.')
        ->assertSuccessful();

    expect($banService->isBanned('198.51.100.77'))->toBeFalse();

    $this->assertDatabaseHas('security_bans', [
        'ip_address' => '198.51.100.77',
        'is_active' => false,
    ]);
});

it('rejects invalid IP address in security:unban', function (): void {
    $this->artisan('security:unban', ['ip' => 'invalid-ip-format'])
        ->expectsOutputToContain("Invalid IP address format: 'invalid-ip-format'")
        ->assertFailed();
});

it('skips security:sync-geoip when not due unless force is specified', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update([
        'enabled' => true,
        'countries' => ['US'],
        'sync_interval' => 'weekly',
        'last_sync_at' => now()->subDays(2),
        'last_status' => 'success',
    ]);

    $this->artisan('security:sync-geoip')
        ->expectsOutputToContain('Geo-Blocking sync is not due yet')
        ->assertSuccessful();

    $mockGeo = Mockery::mock(GeoBlockingService::class);
    $mockGeo->shouldReceive('sync')->once()->andReturn([
        'status' => 'success',
        'v4_count' => 10,
        'v6_count' => 5,
    ]);
    $this->app->instance(GeoBlockingService::class, $mockGeo);

    $this->artisan('security:sync-geoip', ['--force' => true])
        ->expectsOutputToContain('Geo-Blocking sync completed successfully')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| security:verify Console Command Tests
|--------------------------------------------------------------------------
*/

it('exits 0 and displays table when firewall is in sync', function (): void {
    $mockVerifier = Mockery::mock(FirewallSyncVerifier::class);
    $mockVerifier->shouldReceive('verify')->once()->andReturn(
        FirewallSyncStatus::inSync(
            'sha256:1111111111111111111111111111111111111111111111111111111111111111',
            'sha256:1111111111111111111111111111111111111111111111111111111111111111',
            'drop',
            '2026-09-20T12:00:00Z'
        )
    );
    $this->app->instance(FirewallSyncVerifier::class, $mockVerifier);

    $this->artisan('security:verify')
        ->expectsOutputToContain('IN_SYNC')
        ->expectsOutputToContain('SUCCESS: Firewall configuration is in sync')
        ->assertSuccessful();
});

it('exits 1 and logs warning when firewall drift is detected', function (): void {
    Log::spy();

    $mockVerifier = Mockery::mock(FirewallSyncVerifier::class);
    $mockVerifier->shouldReceive('verify')->once()->andReturn(
        FirewallSyncStatus::drift(
            issues: ['Desired ruleset configuration differs from applied ruleset.'],
            desiredDigest: 'sha256:2222222222222222222222222222222222222222222222222222222222222222',
            appliedDigest: 'sha256:1111111111111111111111111111111111111111111111111111111111111111',
            appliedPolicy: 'drop',
            appliedAt: '2026-09-20T12:00:00Z'
        )
    );
    $this->app->instance(FirewallSyncVerifier::class, $mockVerifier);

    $this->artisan('security:verify')
        ->expectsOutputToContain('DRIFT')
        ->expectsOutputToContain('Desired ruleset configuration differs from applied ruleset.')
        ->assertFailed();

    Log::shouldHaveReceived('warning')
        ->with('Firewall sync drift detected', Mockery::type('array'))
        ->once();
});

it('exits 0 with warning when status is unknown in default mode', function (): void {
    $mockVerifier = Mockery::mock(FirewallSyncVerifier::class);
    $mockVerifier->shouldReceive('verify')->once()->andReturn(
        FirewallSyncStatus::unknown(['No verified apply record found (sidecar missing).'])
    );
    $this->app->instance(FirewallSyncVerifier::class, $mockVerifier);

    $this->artisan('security:verify')
        ->expectsOutputToContain('UNKNOWN')
        ->expectsOutputToContain('WARNING: Firewall sync status could not be verified')
        ->assertSuccessful();
});

it('exits 2 when status is unknown in strict mode', function (): void {
    $mockVerifier = Mockery::mock(FirewallSyncVerifier::class);
    $mockVerifier->shouldReceive('verify')->once()->andReturn(
        FirewallSyncStatus::unknown(['No verified apply record found (sidecar missing).'])
    );
    $this->app->instance(FirewallSyncVerifier::class, $mockVerifier);

    $this->artisan('security:verify', ['--strict' => true])
        ->expectsOutputToContain('UNKNOWN')
        ->expectsOutputToContain('strict mode')
        ->assertExitCode(2);
});

it('outputs structured JSON when --json option is provided to security:verify', function (): void {
    $mockVerifier = Mockery::mock(FirewallSyncVerifier::class);
    $mockVerifier->shouldReceive('verify')->once()->andReturn(
        FirewallSyncStatus::inSync(
            'sha256:1111111111111111111111111111111111111111111111111111111111111111',
            'sha256:1111111111111111111111111111111111111111111111111111111111111111',
            'drop',
            '2026-09-20T12:00:00Z'
        )
    );
    $this->app->instance(FirewallSyncVerifier::class, $mockVerifier);

    $this->artisan('security:verify', ['--json' => true])
        ->expectsOutputToContain('"state": "in_sync"')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| security:reconcile Console Command Tests
|--------------------------------------------------------------------------
*/

it('executes reconciliation and displays summary table', function (): void {
    $reconcilerMock = Mockery::mock(FirewallBanReconciler::class);
    $reconcilerMock->shouldReceive('reconcile')
        ->once()
        ->with(false, false)
        ->andReturn([
            'boot_recovered' => false,
            'added' => ['198.51.100.22' => 3600],
            'removed' => ['203.0.113.44'],
            'retimed' => [],
            'skipped' => [],
            'errors' => [],
        ]);
    app()->instance(FirewallBanReconciler::class, $reconcilerMock);

    $this->artisan('security:reconcile')
        ->expectsOutputToContain('=== TallPBX Firewall Ban-Set Reconciliation ===')
        ->expectsOutputToContain('Restored Kernel Bans:')
        ->expectsOutputToContain('198.51.100.22 (3600s TTL)')
        ->expectsOutputToContain('Removed Extraneous Bans:')
        ->expectsOutputToContain('203.0.113.44')
        ->expectsOutputToContain('Reconciliation completed successfully.')
        ->assertExitCode(0);
});

it('supports --dry-run option in security:reconcile without modifying host', function (): void {
    $reconcilerMock = Mockery::mock(FirewallBanReconciler::class);
    $reconcilerMock->shouldReceive('reconcile')
        ->once()
        ->with(true, false)
        ->andReturn([
            'boot_recovered' => false,
            'added' => ['198.51.100.22' => 3600],
            'removed' => [],
            'retimed' => [],
            'skipped' => [],
            'errors' => [],
        ]);
    app()->instance(FirewallBanReconciler::class, $reconcilerMock);

    $this->artisan('security:reconcile --dry-run')
        ->expectsOutputToContain('DRY RUN: Demonstrating planned reconciliation without executing host changes.')
        ->assertExitCode(0);
});

it('supports --recover-boot option in security:reconcile', function (): void {
    $reconcilerMock = Mockery::mock(FirewallBanReconciler::class);
    $reconcilerMock->shouldReceive('reconcile')
        ->once()
        ->with(false, true)
        ->andReturn([
            'boot_recovered' => true,
            'added' => [],
            'removed' => [],
            'retimed' => [],
            'skipped' => [],
            'errors' => [],
        ]);
    app()->instance(FirewallBanReconciler::class, $reconcilerMock);

    $this->artisan('security:reconcile --recover-boot')
        ->expectsOutputToContain('BOOT RECOVERY: Missing kernel table tallpbx_filter restored and applied successfully.')
        ->assertExitCode(0);
});

it('outputs structured json with --json option in security:reconcile', function (): void {
    $reconcilerMock = Mockery::mock(FirewallBanReconciler::class);
    $reconcilerMock->shouldReceive('reconcile')
        ->once()
        ->with(false, false)
        ->andReturn([
            'boot_recovered' => false,
            'added' => ['198.51.100.22' => 3600],
            'removed' => [],
            'retimed' => [],
            'skipped' => [],
            'errors' => [],
        ]);
    app()->instance(FirewallBanReconciler::class, $reconcilerMock);

    $this->artisan('security:reconcile --json')
        ->expectsOutputToContain('"boot_recovered": false')
        ->assertExitCode(0);
});

it('returns failure code when security:reconcile reports errors', function (): void {
    $reconcilerMock = Mockery::mock(FirewallBanReconciler::class);
    $reconcilerMock->shouldReceive('reconcile')
        ->once()
        ->with(false, false)
        ->andReturn([
            'boot_recovered' => false,
            'added' => [],
            'removed' => [],
            'retimed' => [],
            'skipped' => [],
            'errors' => ['Kernel set operation failed: permission denied'],
        ]);
    app()->instance(FirewallBanReconciler::class, $reconcilerMock);

    $this->artisan('security:reconcile')
        ->expectsOutputToContain('Errors Encountered:')
        ->expectsOutputToContain('Kernel set operation failed: permission denied')
        ->assertExitCode(1);
});

/*
|--------------------------------------------------------------------------
| security:observe Console Command Tests
|--------------------------------------------------------------------------
*/

it('runs security:observe when observe mode is disabled and reports enforcing status', function (): void {
    SecuritySetting::set('firewall_observe_mode', false);

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('status')
        ->once()
        ->andReturn("table inet tallpbx_filter {\n chain input { type filter hook input priority -10; policy drop; }\n}");
    $mockExecutor->shouldReceive('observeEvents')
        ->once()
        ->with(20)
        ->andReturn([]);

    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:observe')
        ->expectsOutputToContain('Global Observe Mode')
        ->expectsOutputToContain('STATUS: DISABLED')
        ->expectsOutputToContain('TOTAL OBSERVED WOULD-BE DROPS')
        ->assertSuccessful();
});

it('runs security:observe when observe mode is active and displays hit counters and events', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('status')
        ->once()
        ->andReturn("table inet tallpbx_filter {\n chain input {\n ip saddr @banned_ips counter packets 42 bytes 2100 log prefix \"tallpbx-observe:bans \" limit rate 100/minute\n }\n}");
    $mockExecutor->shouldReceive('observeEvents')
        ->once()
        ->with(20)
        ->andReturn([
            [
                'timestamp' => '2026-10-08 22:44:33',
                'raw_timestamp' => '2026-10-08T22:44:33-07:00',
                'stage' => 'bans',
                'stage_label' => 'Banned Attacker',
                'interface' => 'veth-host',
                'src_ip' => '10.254.254.2',
                'dst_ip' => '10.254.254.1',
                'proto' => 'UDP',
                'spt' => '43032',
                'dpt' => '69',
                'raw' => 'raw-line',
            ],
        ]);

    $this->app->instance(SecurityExecutorInterface::class, $mockExecutor);

    $this->artisan('security:observe')
        ->expectsOutputToContain('STATUS: ACTIVE')
        ->expectsOutputToContain('Banned Attackers')
        ->expectsOutputToContain('42')
        ->expectsOutputToContain('10.254.254.2')
        ->assertSuccessful();
});
