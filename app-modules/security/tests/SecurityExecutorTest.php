<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Modules\Security\Services\Firewall\SecurityExecutor;
use Symfony\Component\Process\Process;

/**
 * Tests for SecurityExecutor and the tallpbx-security bounded helper script.
 *
 * Verifies process construction, argument mapping, graceful error handling,
 * and strict bash input validation (IP format, seconds, pending file checks).
 *
 * Script executions redirect the helper to an isolated temporary configuration
 * directory so the suite never reads or rewrites live host firewall state.
 */
it('builds expected command arguments for ban action', function (): void {
    $executor = new SecurityExecutor('/usr/local/sbin/tallpbx-security');

    $process = $executor->createProcess(['ban', '203.0.113.50', '3600']);
    $commandLine = $process->getCommandLine();

    expect($commandLine)->toContain('tallpbx-security')
        ->and($commandLine)->toContain('ban')
        ->and($commandLine)->toContain('203.0.113.50')
        ->and($commandLine)->toContain('3600');
});

it('builds expected command arguments for unban action', function (): void {
    $executor = new SecurityExecutor('/usr/local/sbin/tallpbx-security');

    $process = $executor->createProcess(['unban', '203.0.113.50']);
    $commandLine = $process->getCommandLine();

    expect($commandLine)->toContain('tallpbx-security')
        ->and($commandLine)->toContain('unban')
        ->and($commandLine)->toContain('203.0.113.50');
});

it('builds expected command arguments for apply action', function (): void {
    $executor = new SecurityExecutor('/usr/local/sbin/tallpbx-security');

    $process = $executor->createProcess(['apply']);
    $commandLine = $process->getCommandLine();

    expect($commandLine)->toContain('tallpbx-security')
        ->and($commandLine)->toContain('apply');
});

it('builds expected command arguments for status action', function (): void {
    $executor = new SecurityExecutor('/usr/local/sbin/tallpbx-security');

    $process = $executor->createProcess(['status']);
    $commandLine = $process->getCommandLine();

    expect($commandLine)->toContain('tallpbx-security')
        ->and($commandLine)->toContain('status');
});

it('returns false gracefully when helper script does not exist', function (): void {
    $executor = new SecurityExecutor('/nonexistent/path/tallpbx-security');

    expect($executor->ban('192.0.2.1', 3600))->toBeFalse()
        ->and($executor->unban('192.0.2.1'))->toBeFalse()
        ->and($executor->apply())->toBeFalse()
        ->and($executor->updateExternalBlocklist())->toBeFalse()
        ->and($executor->updateThreatFeed())->toBeFalse()
        ->and($executor->status())->toBe('');
});

// Note: Direct shell execution and CLI argument validation for the
// scripts/resources/tallpbx-security bounded helper script are tested
// in Tests\\Feature\\Modules\\Security\\SecurityScriptHelperTest.

it('parses structured kernel bans in SecurityExecutor::bans()', function (): void {
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_executor_bans_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    $jsonFixture = json_encode([
        'nftables' => [
            [
                'set' => [
                    'family' => 'inet',
                    'name' => 'banned_ips',
                    'table' => 'tallpbx_filter',
                    'type' => 'ipv4_addr',
                    'elem' => [
                        ['elem' => ['val' => '198.51.100.42', 'timeout' => 3600, 'expires' => 3200]],
                        ['elem' => ['val' => '203.0.113.99', 'timeout' => 86400, 'expires' => 86000]],
                    ],
                ],
            ],
            [
                'set' => [
                    'family' => 'inet',
                    'name' => 'banned_ips6',
                    'table' => 'tallpbx_filter',
                    'type' => 'ipv6_addr',
                    'elem' => [
                        ['elem' => ['val' => '2001:db8::42', 'timeout' => 7200, 'expires' => 7100]],
                    ],
                ],
            ],
            [
                'set' => [
                    'family' => 'inet',
                    'name' => 'whitelist_ips',
                    'table' => 'tallpbx_filter',
                    'type' => 'ipv4_addr',
                    'elem' => ['192.168.1.1'],
                ],
            ],
        ],
    ]);

    $stubHelper = $isolatedDir.'/stub-helper';
    file_put_contents(
        $stubHelper,
        "#!/bin/bash\n".
        "if [ \"\$1\" = \"bans\" ]; then\n".
        "    echo '{$jsonFixture}'\n".
        "    exit 0\n".
        "fi\n".
        "exit 1\n"
    );
    chmod($stubHelper, 0755);

    try {
        $executor = new SecurityExecutor($stubHelper);
        $bans = $executor->bans();

        expect($bans)->toHaveCount(3)
            ->and($bans)->toHaveKeys(['198.51.100.42', '203.0.113.99', '2001:db8::42'])
            ->and($bans['198.51.100.42'])->toBe([
                'ip' => '198.51.100.42',
                'timeout' => 3600,
                'expires' => 3200,
                'family' => 'ipv4',
            ])
            ->and($bans['2001:db8::42'])->toBe([
                'ip' => '2001:db8::42',
                'timeout' => 7200,
                'expires' => 7100,
                'family' => 'ipv6',
            ]);
    } finally {
        @unlink($stubHelper);
        @rmdir($isolatedDir);
    }
});

it('never executes the installed system helper during a test run', function (): void {
    // Safety contract: automated tests must never spawn the privileged
    // /usr/local/sbin helper. On a host where the helper is installed this
    // call used to run real nftables queries (seconds of work per call);
    // it must short-circuit exactly like SecurityExecutor::runCommand() does.
    // Custom stub helpers (test-owned scripts) stay executable so protocol
    // and parsing tests keep exercising the output contract.
    $executor = new SecurityExecutor('/usr/local/sbin/tallpbx-security');

    expect($executor->status())->toBe('')
        ->and($executor->bans())->toBe([]);
});

it('caches repeated kernel status reads and re-reads after invalidation', function (): void {
    // Stub helper that counts how often the kernel status is really queried.
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_status_cache_'.uniqid();
    mkdir($isolatedDir, 0700, true);
    $counterFile = $isolatedDir.'/runs.log';
    $stubHelper = $isolatedDir.'/stub-helper';
    file_put_contents(
        $stubHelper,
        "#!/bin/bash\n".
        "if [ \"\$1\" = \"status\" ]; then\n".
        "    echo run >> {$counterFile}\n".
        "    echo 'table inet tallpbx_filter {'\n".
        "    exit 0\n".
        "fi\n".
        "exit 1\n"
    );
    chmod($stubHelper, 0755);

    try {
        $executor = new SecurityExecutor($stubHelper);
        $runs = static fn (): int => substr_count((string) file_get_contents($counterFile), 'run');

        // Repeated reads inside the cache window share one kernel query.
        expect($executor->status())->toContain('table inet tallpbx_filter')
            ->and($executor->status())->toContain('table inet tallpbx_filter')
            ->and($runs())->toBe(1);

        // After invalidation the next read queries the kernel again.
        $executor->clearStatusCache();

        $executor->status();

        expect($runs())->toBe(2);
    } finally {
        @unlink($stubHelper);
        @unlink($counterFile);
        @rmdir($isolatedDir);
    }
});
