<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Mockery;
use Modules\Security\Services\FirewallBanReconciler;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->seed(SecurityServiceSeeder::class);
});

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

it('supports --dry-run option without modifying host', function (): void {
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

it('supports --recover-boot option', function (): void {
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

it('outputs structured json with --json option', function (): void {
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

it('returns failure code when reconciliation reports errors', function (): void {
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
