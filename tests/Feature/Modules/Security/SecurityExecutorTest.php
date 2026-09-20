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

it('routes bans and unbans for each address family to the matching kernel set', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // Run the helper with the shell trace enabled (-x) so the test can assert
    // which kernel set the command targets. The unban action's kernel calls
    // are read-only or fully ignored, so nothing can mutate host state.

    // IPv6 addresses must target the dedicated banned_ips6 set.
    $v6 = new Process(['bash', '-x', $scriptPath, 'unban', '2001:db8::1']);
    $v6->run();

    expect($v6->getExitCode())->toBe(0)
        ->and($v6->getErrorOutput())->toContain("banned_ips6 '{' 2001:db8::1");

    // IPv4 addresses keep targeting the original banned_ips set.
    $v4 = new Process(['bash', '-x', $scriptPath, 'unban', '203.0.113.9']);
    $v4->run();

    expect($v4->getExitCode())->toBe(0)
        ->and($v4->getErrorOutput())->toContain("banned_ips '{' 203.0.113.9")
        ->and($v4->getErrorOutput())->not->toContain('banned_ips6');
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

it('applies ruleset atomically, verifies live kernel, and creates sidecar when policy matches', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_apply_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    // Create a stub nft binary that mimics nft behavior in tests:
    // -c and -f exit 0.
    // 'list chain' outputs a mock input chain with 'policy drop;'
    $stubNft = $isolatedDir.'/stub-nft';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\n".
        "if [ \"\$1\" = \"list\" ] && [ \"\$2\" = \"chain\" ]; then\n".
        "    echo 'type filter hook input priority -10; policy drop;'\n".
        "fi\n".
        "exit 0\n"
    );
    chmod($stubNft, 0755);

    $digest = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    file_put_contents(
        $isolatedDir.'/firewall.nft.pending',
        "#!/usr/sbin/nft -f\n".
        "# tallpbx-policy: drop\n".
        "# tallpbx-digest: {$digest}\n".
        "table inet tallpbx_filter {}\n"
    );

    try {
        $process = new Process(
            ['bash', $scriptPath, 'apply'],
            null,
            [
                'TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir,
                'TALLPBX_NFT_BIN' => $stubNft,
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('SUCCESS: Ruleset applied atomically and verified against the live kernel');

        $sidecar = $isolatedDir.'/firewall.nft.applied';
        expect(file_exists($sidecar))->toBeTrue();

        $sidecarContent = (string) file_get_contents($sidecar);
        expect($sidecarContent)->toContain("digest={$digest}")
            ->and($sidecarContent)->toContain('policy=drop')
            ->and($sidecarContent)->toMatch('/applied_at=[0-9]{4}-[0-9]{2}-[0-9]{2}T/');
    } finally {
        @unlink($isolatedDir.'/firewall.nft');
        @unlink($isolatedDir.'/firewall.nft.pending');
        @unlink($isolatedDir.'/firewall.nft.applied');
        @unlink($stubNft);
        @rmdir($isolatedDir);
    }
});

it('deletes existing sidecar and warns when live policy does not match declared policy', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_mismatch_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    // Mock nft reports policy accept while ruleset declares policy drop
    $stubNft = $isolatedDir.'/stub-nft';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\n".
        "if [ \"\$1\" = \"list\" ] && [ \"\$2\" = \"chain\" ]; then\n".
        "    echo 'type filter hook input priority -10; policy accept;'\n".
        "fi\n".
        "exit 0\n"
    );
    chmod($stubNft, 0755);

    // Pre-create an old sidecar to verify it gets invalidated on failure
    file_put_contents($isolatedDir.'/firewall.nft.applied', "digest=old\npolicy=accept\napplied_at=yesterday\n");

    $digest = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    file_put_contents(
        $isolatedDir.'/firewall.nft.pending',
        "#!/usr/sbin/nft -f\n".
        "# tallpbx-policy: drop\n".
        "# tallpbx-digest: {$digest}\n".
        "table inet tallpbx_filter {}\n"
    );

    try {
        $process = new Process(
            ['bash', $scriptPath, 'apply'],
            null,
            [
                'TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir,
                'TALLPBX_NFT_BIN' => $stubNft,
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getErrorOutput())->toContain('WARNING: Ruleset applied, but the live kernel policy could not be verified - sync sidecar invalidated');

        // The stale sidecar must have been removed
        expect(file_exists($isolatedDir.'/firewall.nft.applied'))->toBeFalse();
    } finally {
        @unlink($isolatedDir.'/firewall.nft');
        @unlink($isolatedDir.'/firewall.nft.pending');
        @unlink($isolatedDir.'/firewall.nft.applied');
        @unlink($stubNft);
        @rmdir($isolatedDir);
    }
});
