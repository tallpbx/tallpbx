<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Security\Services\FirewallSyncVerifier;
use Modules\Security\Support\FirewallSyncStatus;

/**
 * Feature tests for the security:verify console command.
 *
 * Verifies exit codes, monitoring output formatting, --strict enforcement,
 * --json representation, and drift audit logging.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

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

it('outputs structured JSON when --json option is provided', function (): void {
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
