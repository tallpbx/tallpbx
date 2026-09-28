<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Mockery;
use Modules\Security\Contracts\SecurityBanServiceInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Exceptions\LockoutException;
use Modules\Security\Services\LockoutGuardService;
use Modules\Security\Services\SecurityConfigGenerator;

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

    $mockExecutor = Mockery::mock(SecurityExecutorInterface::class);
    $mockExecutor->shouldReceive('apply')->once()->andReturn(true);

    $this->app->instance(LockoutGuardService::class, $mockGuard);
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
