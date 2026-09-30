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
        ->and($executor->updateThreatFeed())->toBeFalse()
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

it('validates shell script rejects invalid IP in flush-conntrack command', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // Malformed addresses must be refused before any conntrack operation runs
    // (exit code 2 = validation failure), exactly like the ban action.
    $invalidIps = [
        'malicious;ip',
        '344.34.34.34',
        '10.0.0.0/99',
        '1:::2',
    ];

    foreach ($invalidIps as $ip) {
        $process = new Process(['bash', $scriptPath, 'flush-conntrack', $ip]);
        $process->run();

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('ERROR: Invalid IP address');
    }
});

it('reports a missing conntrack utility clearly without crashing', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // Servers without the 'conntrack' package must degrade gracefully: the
    // helper reports the missing binary plainly so the operator can install
    // it, instead of failing with an opaque command-not-found error.
    $process = new Process(
        ['bash', $scriptPath, 'flush-conntrack', '203.0.113.9'],
        null,
        ['TALLPBX_CONNTRACK_BIN' => '/nonexistent/conntrack-binary'],
    );
    $process->run();

    expect($process->getExitCode())->toBe(4)
        ->and($process->getErrorOutput())->toContain('conntrack');
});

it('routes flush-conntrack by address family with the correct conntrack flag', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // A stub conntrack binary records the arguments the helper passes, so the
    // test can assert family routing without touching real kernel state.
    $stubDir = sys_get_temp_dir().'/tallpbx_conntrack_stub_'.uniqid();
    mkdir($stubDir, 0700, true);
    $stub = $stubDir.'/conntrack';
    file_put_contents($stub, "#!/bin/bash\necho \"ARGS: \$*\"\n");
    chmod($stub, 0755);

    try {
        // IPv4 uses the default family invocation.
        $v4 = new Process(
            ['bash', $scriptPath, 'flush-conntrack', '203.0.113.9'],
            null,
            ['TALLPBX_CONNTRACK_BIN' => $stub],
        );
        $v4->run();

        expect($v4->getExitCode())->toBe(0)
            ->and($v4->getOutput())->toContain('ARGS: -D -s 203.0.113.9')
            ->and($v4->getOutput())->not->toContain('-f ipv6');

        // IPv6 must select the ipv6 family explicitly.
        $v6 = new Process(
            ['bash', $scriptPath, 'flush-conntrack', '2001:db8::1'],
            null,
            ['TALLPBX_CONNTRACK_BIN' => $stub],
        );
        $v6->run();

        expect($v6->getExitCode())->toBe(0)
            ->and($v6->getOutput())->toContain('ARGS: -D -f ipv6 -s 2001:db8::1');
    } finally {
        @unlink($stub);
        @rmdir($stubDir);
    }
});

it('treats an idle flush as success when conntrack deletes nothing', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // The real conntrack utility exits non-zero with "0 flow entries have
    // been deleted" when the address has no live sessions — that is the
    // desired end state, not a failure. Banning an idle address must not
    // produce a false 'conntrack flush failed' audit entry.
    $stubDir = sys_get_temp_dir().'/tallpbx_conntrack_idle_stub_'.uniqid();
    mkdir($stubDir, 0700, true);
    $stub = $stubDir.'/conntrack';
    file_put_contents($stub, "#!/bin/bash\necho 'conntrack v1.4.8 (conntrack-tools): 0 flow entries have been deleted.'\nexit 1\n");
    chmod($stub, 0755);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'flush-conntrack', '203.0.113.9'],
            null,
            ['TALLPBX_CONNTRACK_BIN' => $stub],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('0 flow entries have been deleted')
            ->and($process->getOutput())->toContain('SUCCESS: Flushed conntrack entries');
    } finally {
        @unlink($stub);
        @rmdir($stubDir);
    }
});

it('fails loudly when the conntrack flush really fails', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // A genuine failure (kernel interface missing, permissions) must not be
    // mistaken for an idle flush: the helper exits non-zero and surfaces the
    // utility's error text so the operator can act on it.
    $stubDir = sys_get_temp_dir().'/tallpbx_conntrack_fail_stub_'.uniqid();
    mkdir($stubDir, 0700, true);
    $stub = $stubDir.'/conntrack';
    file_put_contents($stub, "#!/bin/bash\necho 'conntrack: Operation not permitted' >&2\nexit 1\n");
    chmod($stub, 0755);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'flush-conntrack', '203.0.113.9'],
            null,
            ['TALLPBX_CONNTRACK_BIN' => $stub],
        );
        $process->run();

        expect($process->getExitCode())->toBe(5)
            ->and($process->getErrorOutput())->toContain('Operation not permitted');
    } finally {
        @unlink($stub);
        @rmdir($stubDir);
    }
});

it('self-reports the helper capability version marker', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // PHP refuses threat-feed actions against helpers older than version 2,
    // so the script must self-report what it supports.
    $process = new Process(['bash', $scriptPath, 'version']);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('tallpbx-helper-version: 2');
});

it('update-threat-feed requires the canonical pending file', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_exec_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'update-threat-feed'],
            null,
            ['TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir],
        );
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Pending threat feed file not found');
    } finally {
        @rmdir($isolatedDir);
    }
});

it('update-threat-feed ignores any path argument and only reads the canonical file', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // The helper contract is a NO-argument action: even when an argument is
    // supplied, the promoted and loaded file must be the canonical pending
    // path inside the configuration directory — never the argument.
    $stubDir = sys_get_temp_dir().'/tallpbx_nft_stub_'.uniqid();
    mkdir($stubDir, 0700, true);
    $nftStub = $stubDir.'/nft';
    file_put_contents($nftStub, "#!/bin/bash\necho \"NFT: \$*\"\nexit 0\n");
    chmod($nftStub, 0755);

    $isolatedDir = sys_get_temp_dir().'/tallpbx_feed_helper_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);
    file_put_contents($isolatedDir.'/threat_feed.nft.pending', "# feed elements\n");

    try {
        $process = new Process(
            ['bash', $scriptPath, 'update-threat-feed', '/tmp/evil-path.nft'],
            null,
            ['TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir, 'TALLPBX_NFT_BIN' => $nftStub],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain("NFT: -c -f {$isolatedDir}/threat_feed.nft.pending")
            ->and($process->getOutput())->toContain("NFT: -f {$isolatedDir}/threat_feed.nft")
            ->and($process->getOutput())->not->toContain('/tmp/evil-path.nft')
            ->and(file_exists($isolatedDir.'/threat_feed.nft'))->toBeTrue()
            ->and(file_exists($isolatedDir.'/threat_feed.nft.pending'))->toBeFalse();
    } finally {
        @unlink($isolatedDir.'/threat_feed.nft');
        @rmdir($isolatedDir);
        @unlink($nftStub);
        @rmdir($stubDir);
    }
});

it('reloads the last good threat feed file after every successful apply', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // The main ruleset loads with `flush ruleset`, which empties the feed
    // sets — so a successful apply must re-load the last good feed file
    // (invariant 5) right after the main ruleset.
    $stubDir = sys_get_temp_dir().'/tallpbx_nft_stub_'.uniqid();
    mkdir($stubDir, 0700, true);
    $nftStub = $stubDir.'/nft';
    file_put_contents($nftStub, "#!/bin/bash\necho \"NFT: \$*\"\nexit 0\n");
    chmod($nftStub, 0755);

    $isolatedDir = sys_get_temp_dir().'/tallpbx_feed_apply_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);
    file_put_contents($isolatedDir.'/firewall.nft.pending', "#!/usr/sbin/nft -f\n# main ruleset\n");
    file_put_contents($isolatedDir.'/threat_feed.nft', "# feed elements\n");

    try {
        $process = new Process(
            ['bash', $scriptPath, 'apply'],
            null,
            ['TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir, 'TALLPBX_NFT_BIN' => $nftStub],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0);

        $output = $process->getOutput();
        $mainLoad = strpos($output, "NFT: -f {$isolatedDir}/firewall.nft");
        $feedCheck = strpos($output, "NFT: -c -f {$isolatedDir}/threat_feed.nft");
        $feedLoad = strpos($output, "NFT: -f {$isolatedDir}/threat_feed.nft");

        expect($mainLoad)->not->toBeFalse()
            ->and($feedCheck)->not->toBeFalse()
            ->and($feedLoad)->not->toBeFalse()
            ->and($mainLoad)->toBeLessThan($feedCheck)
            ->and($feedCheck)->toBeLessThan($feedLoad);
    } finally {
        foreach (glob($isolatedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($isolatedDir);
        @unlink($nftStub);
        @rmdir($stubDir);
    }
});

it('reloads the last good threat feed file when ban restores a missing ruleset', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    // Restoring a missing kernel table from the active file is a full ruleset
    // load (`flush ruleset`), so the feed elements file must be re-loaded
    // right after it — exactly like the apply path (invariant 5).
    $stubDir = sys_get_temp_dir().'/tallpbx_nft_stub_'.uniqid();
    mkdir($stubDir, 0700, true);
    $nftStub = $stubDir.'/nft';
    file_put_contents($nftStub, "#!/bin/bash\nif [ \"\$1\" = \"list\" ]; then exit 1; fi\necho \"NFT: \$*\"\nexit 0\n");
    chmod($nftStub, 0755);

    $isolatedDir = sys_get_temp_dir().'/tallpbx_feed_ban_restore_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);
    file_put_contents($isolatedDir.'/firewall.nft', "#!/usr/sbin/nft -f\n# main ruleset\n");
    file_put_contents($isolatedDir.'/threat_feed.nft', "# feed elements\n");

    try {
        $process = new Process(
            ['bash', $scriptPath, 'ban', '198.51.100.9', '3600'],
            null,
            ['TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir, 'TALLPBX_NFT_BIN' => $nftStub],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0);

        $output = $process->getOutput();
        $mainLoad = strpos($output, "NFT: -f {$isolatedDir}/firewall.nft");
        $feedLoad = strpos($output, "NFT: -f {$isolatedDir}/threat_feed.nft");

        expect($mainLoad)->not->toBeFalse()
            ->and($feedLoad)->not->toBeFalse()
            ->and($mainLoad)->toBeLessThan($feedLoad);
    } finally {
        foreach (glob($isolatedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($isolatedDir);
        @unlink($nftStub);
        @rmdir($stubDir);
    }
});

it('reloads the last good threat feed file when unban restores a missing ruleset', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    $stubDir = sys_get_temp_dir().'/tallpbx_nft_stub_'.uniqid();
    mkdir($stubDir, 0700, true);
    $nftStub = $stubDir.'/nft';
    file_put_contents($nftStub, "#!/bin/bash\nif [ \"\$1\" = \"list\" ]; then exit 1; fi\necho \"NFT: \$*\"\nexit 0\n");
    chmod($nftStub, 0755);

    $isolatedDir = sys_get_temp_dir().'/tallpbx_feed_unban_restore_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);
    file_put_contents($isolatedDir.'/firewall.nft', "#!/usr/sbin/nft -f\n# main ruleset\n");
    file_put_contents($isolatedDir.'/threat_feed.nft', "# feed elements\n");

    try {
        $process = new Process(
            ['bash', $scriptPath, 'unban', '198.51.100.10'],
            null,
            ['TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir, 'TALLPBX_NFT_BIN' => $nftStub],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0);

        $output = $process->getOutput();
        $mainLoad = strpos($output, "NFT: -f {$isolatedDir}/firewall.nft");
        $feedLoad = strpos($output, "NFT: -f {$isolatedDir}/threat_feed.nft");

        expect($mainLoad)->not->toBeFalse()
            ->and($feedLoad)->not->toBeFalse()
            ->and($mainLoad)->toBeLessThan($feedLoad);
    } finally {
        foreach (glob($isolatedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($isolatedDir);
        @unlink($nftStub);
        @rmdir($stubDir);
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

    $stubNft = $isolatedDir.'/stub-nft';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\nexit 0\n"
    );
    chmod($stubNft, 0755);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'validate'],
            null,
            [
                'TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir,
                'TALLPBX_NFT_BIN' => $stubNft,
            ],
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

it('re-validates the promoted ruleset before loading it into the kernel', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_race_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    // Stub nft that simulates a concurrent writer replacing the pending file
    // with an invalid ruleset right after the first pre-check, and rejects the
    // swapped content on any later validation. Every invocation is logged so
    // the test can prove the kernel apply step was never reached.
    $stubNft = $isolatedDir.'/stub-nft';
    $log = $isolatedDir.'/nft-calls.log';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\n".
        "echo \"\$*\" >> '{$log}'\n".
        "if [ \"\$1\" = \"-c\" ] && [ \"\$3\" = \"\$TALLPBX_FIREWALL_CONF_DIR/firewall.nft.pending\" ]; then\n".
        "    printf 'BROKEN RULESET\\n' > \"\$3\"\n".
        "    exit 0\n".
        "fi\n".
        "if [ \"\$1\" = \"-c\" ] && grep -q 'BROKEN RULESET' \"\$3\"; then\n".
        "    exit 1\n".
        "fi\n".
        "exit 0\n"
    );
    chmod($stubNft, 0755);

    file_put_contents(
        $isolatedDir.'/firewall.nft.pending',
        "#!/usr/sbin/nft -f\n".
        "# tallpbx-policy: drop\n".
        "# tallpbx-digest: sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\n".
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

        // The swapped-in invalid ruleset must never reach the kernel: the
        // helper must abort after re-checking the promoted file, before any
        // 'nft -f' apply invocation happens.
        expect($process->getExitCode())->not->toBe(0)
            ->and((string) file_get_contents($log))->not->toMatch('/^-f /m');
    } finally {
        @unlink($isolatedDir.'/firewall.nft');
        @unlink($isolatedDir.'/firewall.nft.pending');
        @unlink($stubNft);
        @unlink($log);
        @rmdir($isolatedDir);
    }
});

it('validates the active ruleset before restoring it when the kernel table is missing', function (string $action): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_restore_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    // Stub nft that reports the kernel table as missing (forcing the restore
    // path) and rejects the corrupt active ruleset during validation. Every
    // call is logged so the test can prove the restore apply step never ran.
    $stubNft = $isolatedDir.'/stub-nft';
    $log = $isolatedDir.'/nft-calls.log';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\n".
        "echo \"\$*\" >> '{$log}'\n".
        "if [ \"\$1\" = \"list\" ] && [ \"\$2\" = \"table\" ]; then\n".
        "    exit 1\n".
        "fi\n".
        "if [ \"\$1\" = \"-c\" ] && grep -q 'BROKEN RULESET' \"\$3\"; then\n".
        "    exit 1\n".
        "fi\n".
        "exit 0\n"
    );
    chmod($stubNft, 0755);

    // A corrupt active ruleset: restoring it without validation would load
    // broken syntax into the kernel, and the masked failure would go unseen.
    file_put_contents($isolatedDir.'/firewall.nft', "BROKEN RULESET\n");

    // The ban action needs an IP plus seconds; the unban action needs only
    // the IP. Both trigger the same restore block.
    $args = $action === 'ban' ? ['ban', '192.0.2.1', '3600'] : ['unban', '192.0.2.1'];

    try {
        $process = new Process(
            ['bash', $scriptPath, ...$args],
            null,
            [
                'TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir,
                'TALLPBX_NFT_BIN' => $stubNft,
            ],
        );
        $process->run();

        // The helper must fail loudly on the corrupt active file instead of
        // silently skipping the restore and proceeding to the kernel action.
        expect($process->getExitCode())->toBe(1)
            ->and((string) file_get_contents($log))->not->toMatch('/^-f /m');
    } finally {
        @unlink($isolatedDir.'/firewall.nft');
        @unlink($stubNft);
        @unlink($log);
        @rmdir($isolatedDir);
    }
})->with(['ban', 'unban']);

it('runs with a restrictive umask so helper-created files are never world-readable', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_umask_test_'.uniqid();
    mkdir($isolatedDir, 0700, true);

    // Stub nft creates a probe file. Child processes inherit the helper's
    // umask, so the probe's resulting mode reveals the umask the helper runs
    // with: 027 produces 0640, while the permissive default 022 produces 0644.
    $probe = $isolatedDir.'/umask-probe';
    $stubNft = $isolatedDir.'/stub-nft';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\n".
        "printf 'probe' > '{$probe}'\n".
        "exit 0\n"
    );
    chmod($stubNft, 0755);

    file_put_contents(
        $isolatedDir.'/firewall.nft.pending',
        "#!/usr/sbin/nft -f\n\ntable inet tallpbx_umask_probe {}\n"
    );

    try {
        $process = new Process(
            ['bash', $scriptPath, 'validate'],
            null,
            [
                'TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir,
                'TALLPBX_NFT_BIN' => $stubNft,
            ],
        );
        $process->run();

        $probeMode = (int) fileperms($probe) & 0777;
        expect($process->getExitCode())->toBe(0)
            ->and($probeMode)->toBe(0640);
    } finally {
        @unlink($probe);
        @unlink($isolatedDir.'/firewall.nft.pending');
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

it('emits kernel ban sets in structured json via helper bans action', function (): void {
    $scriptPath = base_path('scripts/resources/tallpbx-security');
    $isolatedDir = sys_get_temp_dir().'/tallpbx_security_bans_test_'.uniqid();
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
        ],
    ]);

    $stubNft = $isolatedDir.'/stub-nft';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\n".
        "if [ \"\$1\" = \"list\" ] && [ \"\$2\" = \"table\" ]; then\n".
        "    exit 0\n".
        "fi\n".
        "if [ \"\$1\" = \"-j\" ] && [ \"\$2\" = \"list\" ] && [ \"\$3\" = \"sets\" ]; then\n".
        "    echo '{$jsonFixture}'\n".
        "    exit 0\n".
        "fi\n".
        "exit 1\n"
    );
    chmod($stubNft, 0755);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'bans'],
            null,
            [
                'TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir,
                'TALLPBX_NFT_BIN' => $stubNft,
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0);
        $decoded = json_decode($process->getOutput(), true);
        expect($decoded)->toHaveKey('nftables')
            ->and($decoded['nftables'])->toHaveCount(2);
    } finally {
        @unlink($stubNft);
        @rmdir($isolatedDir);
    }
});

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

it('reads kernel status chain-by-chain without dumping the full table', function (): void {
    // The panel only parses chain rules and the input-hook policy. Dumping
    // the whole table also dumps every threat-feed set element, which costs
    // seconds and megabytes on a live PBX. The status action must therefore
    // list the chain headers and per-chain rules only.
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    $isolatedDir = sys_get_temp_dir().'/tallpbx_nft_status_stub_'.uniqid();
    mkdir($isolatedDir, 0700, true);
    $callsFile = $isolatedDir.'/nft-calls.log';
    $stubNft = $isolatedDir.'/nft';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\n".
        "echo \"\$*\" >> {$callsFile}\n".
        "case \"\$*\" in\n".
        "    'list chains')\n".
        "        printf 'table inet tallpbx_filter {\\n\\tchain input {\\n\\t\\ttype filter hook input priority filter - 10; policy drop;\\n\\t}\\n\\tchain output {\\n\\t\\ttype filter hook output priority filter; policy accept;\\n\\t}\\n}\\n'\n".
        "        ;;\n".
        "    'list chain inet tallpbx_filter input')\n".
        "        printf 'table inet tallpbx_filter {\\n\\tchain input {\\n\\t\\ttype filter hook input priority filter - 10; policy drop;\\n\\t\\tip saddr @threat_feed_ips counter packets 7 bytes 0 drop\\n\\t\\tudp dport 69 @th,64,16 0x0002 counter packets 3 bytes 0 drop\\n\\t\\tudp dport 69 @th,80,24 0x2e2e2f counter packets 2 bytes 0 drop\\n\\t\\tudp dport 69 @th,80,16 0x2f78 counter packets 1 bytes 0 drop\\n\\t\\tudp dport 69 update @tftp_flood4 { ip saddr limit rate over 10/minute burst 20 packets } counter packets 5 bytes 0 drop\\n\\t\\tudp dport 69 update @tftp_flood6 { ip6 saddr limit rate over 10/minute burst 20 packets } counter packets 4 bytes 0 drop\\n\\t}\\n}\\n'\n".
        "        ;;\n".
        "    'list chain inet tallpbx_filter output')\n".
        "        printf 'table inet tallpbx_filter {\\n\\tchain output {\\n\\t\\ttype filter hook output priority filter; policy accept;\\n\\t}\\n}\\n'\n".
        "        ;;\n".
        "    *)\n".
        "        exit 1\n".
        "        ;;\n".
        "esac\n"
    );
    chmod($stubNft, 0755);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'status'],
            null,
            ['TALLPBX_NFT_BIN' => $stubNft, 'TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir],
        );
        $process->run();

        $calls = (string) file_get_contents($callsFile);

        expect($process->getExitCode())->toBe(0)
            // Every output contract the PHP parsers depend on survives…
            ->and($process->getOutput())->toContain('table inet tallpbx_filter')
            ->and($process->getOutput())->toContain('type filter hook input priority filter - 10; policy drop;')
            ->and($process->getOutput())->toContain('ip saddr @threat_feed_ips counter packets 7')
            ->and($process->getOutput())->toContain('@th,64,16 0x0002 counter packets 3')
            ->and($process->getOutput())->toContain('@th,80,24 0x2e2e2f counter packets 2')
            ->and($process->getOutput())->toContain('@th,80,16 0x2f78 counter packets 1')
            ->and($process->getOutput())->toContain('@tftp_flood4')
            ->and($process->getOutput())->toContain('@tftp_flood6')
            // …while the megabyte-scale dumps never run.
            ->and($calls)->toContain('list chains')
            ->and($calls)->toContain('list chain inet tallpbx_filter input')
            ->and($calls)->toContain('list chain inet tallpbx_filter output')
            ->and($calls)->not->toContain('list table')
            ->and($calls)->not->toContain('list ruleset');
    } finally {
        @unlink($stubNft);
        @unlink($callsFile);
        @rmdir($isolatedDir);
    }
});

it('falls back to the full ruleset listing when the TallPBX table is missing', function (): void {
    // Behavior preservation: when the TallPBX firewall table is not loaded,
    // the panel still needs a (non-empty) kernel listing that does NOT contain
    // 'table inet tallpbx_filter' so it can report the firewall as absent.
    $scriptPath = base_path('scripts/resources/tallpbx-security');

    $isolatedDir = sys_get_temp_dir().'/tallpbx_nft_fallback_stub_'.uniqid();
    mkdir($isolatedDir, 0700, true);
    $callsFile = $isolatedDir.'/nft-calls.log';
    $stubNft = $isolatedDir.'/nft';
    file_put_contents(
        $stubNft,
        "#!/bin/bash\n".
        "echo \"\$*\" >> {$callsFile}\n".
        "case \"\$*\" in\n".
        "    'list chains')\n".
        "        printf 'table inet some_other_table {\\n\\tchain input {\\n\\t}\\n}\\n'\n".
        "        ;;\n".
        "    'list ruleset')\n".
        "        printf 'table inet some_other_table {\\n\\tchain input {\\n\\t}\\n}\\n'\n".
        "        ;;\n".
        "    *)\n".
        "        exit 1\n".
        "        ;;\n".
        "esac\n"
    );
    chmod($stubNft, 0755);

    try {
        $process = new Process(
            ['bash', $scriptPath, 'status'],
            null,
            ['TALLPBX_NFT_BIN' => $stubNft, 'TALLPBX_FIREWALL_CONF_DIR' => $isolatedDir],
        );
        $process->run();

        $calls = (string) file_get_contents($callsFile);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('table inet some_other_table')
            ->and($process->getOutput())->not->toContain('table inet tallpbx_filter')
            ->and($calls)->toContain('list ruleset')
            ->and($calls)->not->toContain('list chain inet tallpbx_filter');
    } finally {
        @unlink($stubNft);
        @unlink($callsFile);
        @rmdir($isolatedDir);
    }
});
