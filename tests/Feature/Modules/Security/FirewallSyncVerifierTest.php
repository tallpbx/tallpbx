<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Services\FirewallSyncVerifier;
use Modules\Security\Services\SecurityConfigGenerator;
use Modules\Security\Support\FirewallSyncStatus;

/**
 * Feature tests for FirewallSyncVerifier and FirewallSyncStatus.
 *
 * Verifies kernel table presence inspection, live policy verification,
 * sidecar provenance validation, and deterministic digest comparison.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

it('instantiates FirewallSyncStatus value object and exposes helper methods', function (): void {
    $inSync = FirewallSyncStatus::inSync(
        'sha256:abc',
        'sha256:abc',
        'drop',
        '2026-09-20T12:00:00Z'
    );

    expect($inSync->isInSync())->toBeTrue()
        ->and($inSync->isDrift())->toBeFalse()
        ->and($inSync->isUnknown())->toBeFalse()
        ->and($inSync->state)->toBe('in_sync')
        ->and($inSync->toArray())->toHaveKeys(['state', 'desired_digest', 'applied_digest', 'applied_policy', 'applied_at', 'issues']);

    $drift = FirewallSyncStatus::drift(['Test drift issue']);
    expect($drift->isDrift())->toBeTrue()
        ->and($drift->isInSync())->toBeFalse()
        ->and($drift->issues)->toBe(['Test drift issue']);

    $unknown = FirewallSyncStatus::unknown(['Missing sidecar']);
    expect($unknown->isUnknown())->toBeTrue()
        ->and($unknown->isInSync())->toBeFalse();
});

it('returns in_sync when sidecar matches desired digest and kernel is healthy', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx_verifier_sync_test_'.uniqid();
    mkdir($tempDir, 0700, true);

    $generator = new SecurityConfigGenerator($tempDir);
    $digest = $generator->canonicalDigest();

    // Write a valid matching sidecar
    file_put_contents(
        $tempDir.'/firewall.nft.applied',
        "digest={$digest}\npolicy=drop\napplied_at=2026-09-20T12:00:00Z\n"
    );

    // Mock executor reporting table tallpbx_filter with policy drop
    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('status')->andReturn(
        "table inet tallpbx_filter {\n".
        "    chain input {\n".
        "        type filter hook input priority -10; policy drop;\n".
        "    }\n".
        "}\n"
    );

    try {
        $verifier = new FirewallSyncVerifier($generator, $executor, $tempDir);
        $status = $verifier->verify();

        expect($status->isInSync())->toBeTrue()
            ->and($status->state)->toBe('in_sync')
            ->and($status->desiredDigest)->toBe($digest)
            ->and($status->appliedDigest)->toBe($digest)
            ->and($status->appliedPolicy)->toBe('drop')
            ->and($status->appliedAt)->toBe('2026-09-20T12:00:00Z')
            ->and($status->issues)->toBeEmpty();
    } finally {
        @unlink($tempDir.'/firewall.nft.applied');
        @rmdir($tempDir);
    }
});

it('returns unknown when sidecar file is missing', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx_verifier_missing_test_'.uniqid();
    mkdir($tempDir, 0700, true);

    $generator = new SecurityConfigGenerator($tempDir);
    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('status')->andReturn('');

    try {
        $verifier = new FirewallSyncVerifier($generator, $executor, $tempDir);
        $status = $verifier->verify();

        expect($status->isUnknown())->toBeTrue()
            ->and($status->state)->toBe('unknown')
            ->and($status->issues)->toContain('No verified apply record found (sidecar missing).');
    } finally {
        @rmdir($tempDir);
    }
});

it('returns unknown when sidecar content is malformed', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx_verifier_malformed_test_'.uniqid();
    mkdir($tempDir, 0700, true);

    $generator = new SecurityConfigGenerator($tempDir);
    $executor = Mockery::mock(SecurityExecutorInterface::class);

    // Invalid digest (not a sha256)
    file_put_contents(
        $tempDir.'/firewall.nft.applied',
        "digest=invalid_hash\npolicy=drop\napplied_at=2026-09-20T12:00:00Z\n"
    );

    try {
        $verifier = new FirewallSyncVerifier($generator, $executor, $tempDir);
        $status = $verifier->verify();

        expect($status->isUnknown())->toBeTrue()
            ->and($status->issues)->toContain('Malformed sidecar record.');
    } finally {
        @unlink($tempDir.'/firewall.nft.applied');
        @rmdir($tempDir);
    }
});

it('returns drift when desired digest does not match sidecar digest', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx_verifier_drift_digest_test_'.uniqid();
    mkdir($tempDir, 0700, true);

    $generator = new SecurityConfigGenerator($tempDir);

    // Sidecar has a different digest
    $staleDigest = 'sha256:0000000000000000000000000000000000000000000000000000000000000000';
    file_put_contents(
        $tempDir.'/firewall.nft.applied',
        "digest={$staleDigest}\npolicy=drop\napplied_at=2026-09-20T10:00:00Z\n"
    );

    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('status')->andReturn(
        "table inet tallpbx_filter {\n".
        "    chain input {\n".
        "        type filter hook input priority -10; policy drop;\n".
        "    }\n".
        "}\n"
    );

    try {
        $verifier = new FirewallSyncVerifier($generator, $executor, $tempDir);
        $status = $verifier->verify();

        expect($status->isDrift())->toBeTrue()
            ->and($status->state)->toBe('drift')
            ->and($status->issues)->toContain('Desired ruleset configuration differs from applied ruleset.');
    } finally {
        @unlink($tempDir.'/firewall.nft.applied');
        @rmdir($tempDir);
    }
});

it('returns drift when kernel table is absent even if sidecar matches desired digest', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx_verifier_absent_table_test_'.uniqid();
    mkdir($tempDir, 0700, true);

    $generator = new SecurityConfigGenerator($tempDir);
    $digest = $generator->canonicalDigest();

    file_put_contents(
        $tempDir.'/firewall.nft.applied',
        "digest={$digest}\npolicy=drop\napplied_at=2026-09-20T12:00:00Z\n"
    );

    // Executor returns status where table tallpbx_filter is absent (e.g. after host reboot)
    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('status')->andReturn("table ip filter {\n}\n");

    try {
        $verifier = new FirewallSyncVerifier($generator, $executor, $tempDir);
        $status = $verifier->verify();

        expect($status->isDrift())->toBeTrue()
            ->and($status->issues)->toContain('Kernel firewall table inet tallpbx_filter is absent.');
    } finally {
        @unlink($tempDir.'/firewall.nft.applied');
        @rmdir($tempDir);
    }
});

it('returns drift when live kernel policy differs from declared policy', function (): void {
    $tempDir = sys_get_temp_dir().'/tallpbx_verifier_policy_mismatch_test_'.uniqid();
    mkdir($tempDir, 0700, true);

    $generator = new SecurityConfigGenerator($tempDir);
    $digest = $generator->canonicalDigest();

    file_put_contents(
        $tempDir.'/firewall.nft.applied',
        "digest={$digest}\npolicy=drop\napplied_at=2026-09-20T12:00:00Z\n"
    );

    // Live kernel is running policy accept, but declared/applied is drop
    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('status')->andReturn(
        "table inet tallpbx_filter {\n".
        "    chain input {\n".
        "        type filter hook input priority -10; policy accept;\n".
        "    }\n".
        "}\n"
    );

    try {
        $verifier = new FirewallSyncVerifier($generator, $executor, $tempDir);
        $status = $verifier->verify();

        expect($status->isDrift())->toBeTrue()
            ->and($status->issues)->toContain('Live kernel policy (accept) does not match applied policy (drop).');
    } finally {
        @unlink($tempDir.'/firewall.nft.applied');
        @rmdir($tempDir);
    }
});
