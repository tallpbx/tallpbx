<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Modules\Security\Services\SecurityExecutor;
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
        ->and($executor->status())->toBe('');
});

it('validates shell script usage on invalid action', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    $process = new Process(['bash', $scriptPath, 'invalid_action']);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Usage:');
});

it('validates shell script rejects invalid IP characters in ban command', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    $maliciousIps = [
        '192.168.1.1; rm -rf /',
        'not_an_ip',
        '192.168.1.1 && cat /etc/passwd',
        '999.999.999.999.999',
    ];

    foreach ($maliciousIps as $ip) {
        $process = new Process(['bash', $scriptPath, 'ban', $ip, '3600']);
        $process->run();

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('ERROR: Invalid IP address');
    }
});

it('validates shell script rejects invalid seconds in ban command', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    $invalidDurations = [
        '-50',
        'abc',
        '3600; reboot',
        '10.5',
    ];

    foreach ($invalidDurations as $duration) {
        $process = new Process(['bash', $scriptPath, 'ban', '192.0.2.1', $duration]);
        $process->run();

        expect($process->getExitCode())->toBe(3)
            ->and($process->getErrorOutput())->toContain('ERROR: Invalid seconds duration');
    }
});

it('validates shell script rejects invalid IP in unban command', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // Octet/CIDR bounds and malformed IPv6 must be refused before any kernel
    // operation runs (exit code 2 = validation failure). The unban action is
    // used because its kernel calls are read-only or fully ignored, so an
    // unexpected validation pass can never mutate host firewall state.
    $invalidIps = [
        'malicious;ip',
        '344.34.34.34',
        '10.0.0.0/99',
        '1:::2',
        '2001:db8::/999',
    ];

    foreach ($invalidIps as $ip) {
        $process = new Process(['bash', $scriptPath, 'unban', $ip]);
        $process->run();

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('ERROR: Invalid IP address');
    }
});

it('validates shell script apply fails when pending file is missing', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // Run the helper against a throwaway directory: the real default
    // (/etc/tallpbx) belongs to the live host and must never be read,
    // rewritten, or cleaned up by tests.
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_exec_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'apply'],
            null,
            ['TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir],
        );
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Pending firewall configuration file not found');
    } finally {
        @rmdir($isolatedDir);
    }
});

it('validates shell script validate fails when pending file is missing', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // Same isolation contract as the apply test above: never touch /etc/tallpbx.
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_exec_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'validate'],
            null,
            ['TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir],
        );
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Pending firewall configuration file not found');
    } finally {
        @rmdir($isolatedDir);
    }
});

it('validates shell script validate checks pending ruleset syntax without touching the kernel', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_exec_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    // A minimal syntactically valid ruleset — 'validate' runs 'nft -c' in
    // check-only mode, so the kernel firewall must never be modified.
    file_put_contents(
        $isolatedDir.'/firewall.nft.pending',
        "#!/usr/sbin/nft -f\n\ntable inet tallpbx_validate_probe {\n}\n"
    );

    try {
        $process = new Process(
            ['bash', $scriptPath, 'validate'],
            null,
            ['TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('SUCCESS: Pending ruleset syntax is valid');
    } finally {
        @unlink($isolatedDir.'/firewall.nft.pending');
        @rmdir($isolatedDir);
    }
});
