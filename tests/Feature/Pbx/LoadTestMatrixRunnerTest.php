<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Run the matrix runner with an isolated artifact root.
 *
 * @param  array<int, string>  $arguments  Command-line arguments for the runner
 * @param  string  $artifactRoot  Directory the run may write into
 * @param  array<string, string>  $env  Extra environment variables for the run
 * @return Process The finished process, for output assertions
 */
function matrixRunnerProcess(array $arguments, string $artifactRoot, array $env = []): Process
{
    $process = new Process(
        ['bash', base_path('load-testing/scripts/load-test-matrix.sh'), ...$arguments],
        base_path(),
        array_merge(
            ['ARTIFACT_ROOT' => $artifactRoot, 'SERIES' => basename($artifactRoot)],
            $env,
        ),
    );

    $process->run();

    return $process;
}

/*
|--------------------------------------------------------------------------
| Load-Test Matrix Runner
|--------------------------------------------------------------------------
|
| The runner decides which cells exist, so a typo in a filter or a phase that
| silently disappears would leave holes in the series. These checks pin the
| listing, the dry-run contract (print, never execute) and the filter
| validation.
|
*/

describe('load test matrix runner', function (): void {
    beforeEach(function (): void {
        $this->artifactRoot = base_path('storage/framework/testing/load-tests/matrix-runner-'.uniqid());
        File::deleteDirectory($this->artifactRoot);
    });

    afterEach(function (): void {
        File::deleteDirectory($this->artifactRoot);
    });

    it('is a syntactically valid bash script', function (): void {
        foreach (['load-testing/scripts/load-test-matrix.sh', 'load-testing/scripts/lib/manifest.sh'] as $script) {
            $process = new Process(['bash', '-n', base_path($script)], base_path());
            $process->run();

            expect($process->isSuccessful())->toBeTrue($script.': '.$process->getErrorOutput());
        }
    });

    it('lists every tier and variant for each phase', function (): void {
        $process = matrixRunnerProcess(['--list'], $this->artifactRoot);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $rows = collect(explode("\n", trim($process->getOutput())))
            ->map(fn (string $line): string => implode(' ', preg_split('/\s+/', trim($line))))
            ->sort()
            ->values()
            ->all();

        $tiers = ['h1-1c1g', 'h1-1c2g', 'h2-2c2g', 'h2d-2c8g', 'h4d-4c16g'];
        $variants = ['v-stock-dynamic', 'v-stock-static', 'v-fast', 'v-lua-stock', 'v-lua-fast', 'v-octane'];
        $phases = ['xml', 'sip', 'ablation'];

        $expected = [];

        foreach ($tiers as $tier) {
            foreach ($variants as $variant) {
                foreach ($phases as $phase) {
                    $expected[] = "{$tier} {$variant} {$phase}";
                }
            }
        }

        sort($expected);

        // Exact equality catches both a missing cell and an accidental extra one.
        expect($rows)->toBe($expected);
    });

    it('prints the exact load command per cell without executing it', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-static', '--phase=xml', '--profile=100x5', '--repeats=1'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $output = $process->getOutput();

        expect($output)->toContain('=== h1-1c1g / v-stock-static / xml')
            ->and($output)->toContain('pbx:load-test:dialplan')
            ->and($output)->toContain('--requests=100')
            ->and($output)->toContain('--concurrency=5')
            ->and($output)->toContain('--report=')
            // The teardown and tier preparation must not have run.
            ->and(File::isDirectory($this->artifactRoot.'/h1-1c1g'))->toBeFalse();
    });

    it('prints the SIPp validation command for the sip phase', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h2-2c2g', '--variant=v-octane', '--phase=sip'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        expect($process->getOutput())->toContain('pbx-sipp-validate.sh')
            ->and($process->getOutput())->toContain('TENANT=load-test-beta');
    });

    it('accepts the load-test variable names documented in the guide', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-static', '--phase=sip'],
            $this->artifactRoot,
            ['TENANT' => 'tenant-docs', 'DOMAIN' => 'docs.test', 'EXTENSIONS' => '7', 'START' => '3100'],
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // One vocabulary with scripts/pbx-sipp-validate.sh, so the guide can
        // document the names once.
        expect($process->getOutput())
            ->toContain('TENANT=tenant-docs')
            ->toContain('DOMAIN=docs.test')
            ->toContain('EXTENSIONS=7')
            ->toContain('START=3100');
    });

    it('points the Octane variant at the worker and every other variant at nginx', function (): void {
        $octane = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-octane', '--phase=xml', '--profile=100x5', '--section=dialplan', '--repeats=1'],
            $this->artifactRoot,
        );
        $stock = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-static', '--phase=xml', '--profile=100x5', '--section=dialplan', '--repeats=1'],
            $this->artifactRoot,
        );

        expect($octane->isSuccessful())->toBeTrue($octane->getErrorOutput());

        // The worker is a separate HTTP server. Posting to nginx measures
        // PHP-FPM and would report that number as Octane.
        expect($octane->getOutput())->toContain('--url=http://127.0.0.1:8090/api/v1/xml-handler')
            ->and($stock->getOutput())->toContain('--url=http://127.0.0.1/api/v1/xml-handler')
            ->and($stock->getOutput())->not->toContain('8090');
    });

    it('measures the FreeSWITCH lookup path alongside every XML cell', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-lua-stock', '--phase=xml', '--profile=100x5', '--section=dialplan', '--repeats=1'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // A Lua hit never reaches nginx or PHP, so the HTTP client cannot
        // observe it; the FreeSWITCH probe is the only measurement that can.
        expect($process->getOutput())->toContain('probe_lookup');
    });

    it('restricts the battery to one cache policy when asked', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-static', '--phase=xml', '--profile=100x5', '--section=dialplan', '--ttl=production-60', '--repeats=1'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // Measuring every cache policy for every variant multiplies the run
        // without answering a new question once the baseline has them.
        expect($process->getOutput())
            ->toContain('100x5-production-60-dialplan')
            ->not->toContain('100x5-cold-dialplan')
            ->not->toContain('100x5-cache-hit-60-dialplan');
    });

    it('runs a warm-up cell before the measured ones', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-octane', '--phase=xml', '--profile=100x5', '--ttl=production-60', '--section=dialplan', '--repeats=1'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // The first requests after a switch are always slower: the pool has
        // restarted and the worker has booted. Measuring that as a repeat
        // understates the variant, so a warm-up cell runs first and is kept
        // as evidence rather than counted as a measurement.
        $lines = array_values(array_filter(explode("\n", $process->getOutput())));

        $warmup = null;
        $measured = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, '25x1') && $warmup === null) {
                $warmup = $index;
            }

            if (str_contains($line, '100x5-production-60') && $measured === null) {
                $measured = $index;
            }
        }

        expect($warmup)->not->toBeNull()
            ->and($measured)->not->toBeNull()
            ->and($warmup)->toBeLessThan($measured);
    });

    it('derives the SIP realm from the PBX when the caller gives none', function (): void {
        $fixture = $this->artifactRoot.'-pbx';

        File::ensureDirectoryExists($fixture);
        File::put($fixture.'/.env', "APP_URL=http://10.0.0.9\nFS_DEFAULT_SIP_REALM=realm-from-pbx.test\n");

        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-static', '--phase=sip'],
            $this->artifactRoot,
            ['REMOTE_APP_DIR' => $fixture, 'PBX_HOST' => 'local'],
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // FreeSWITCH resolves a tenant user under the realm it serves. When
        // the host's realm differs from the documented default, seeding the
        // tenant on the wrong one makes every registration answer 401/403.
        expect($process->getOutput())->toContain('DOMAIN=realm-from-pbx.test');

        File::deleteDirectory($fixture);
    });

    it('lets an explicit realm win over the host realm', function (): void {
        $fixture = $this->artifactRoot.'-pbx';

        File::ensureDirectoryExists($fixture);
        File::put($fixture.'/.env', "FS_DEFAULT_SIP_REALM=realm-from-pbx.test\n");

        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-static', '--phase=sip'],
            $this->artifactRoot,
            ['REMOTE_APP_DIR' => $fixture, 'PBX_HOST' => 'local', 'DOMAIN' => 'explicit.test'],
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain('DOMAIN=explicit.test');

        File::deleteDirectory($fixture);
    });

    it('shows the SIP smoke preflight before the series it guards', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-static', '--phase=sip'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // A six-variant SIP series costs about 30 minutes. A broken chain —
        // a tenant that cannot register, a gateway that answers 503, a CDR
        // pipeline that writes nothing — must be found in the first two
        // minutes, not at minute 30. The preflight places one call of each
        // shape and checks the records they leave behind.
        $output = $process->getOutput();
        $lines = explode("\n", $output);

        $smoke = null;
        $series = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, 'pbx-sipp-validate.sh') && $smoke === null) {
                $smoke = $index;
            }

            // The smoke block has its own '===' header, so the series header
            // is the first one that names a variant rather than 'smoke'.
            if (str_contains($line, '===') && ! str_contains($line, 'smoke') && $series === null) {
                $series = $index;
            }
        }

        expect($smoke)->not->toBeNull()
            ->and($series)->not->toBeNull()
            ->and($smoke)->toBeLessThan($series);
    });

    it('keeps the SIP smoke preflight to one call of each shape', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-static', '--phase=sip'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // One registration, one extension call, one outbound call: the whole
        // chain (register, bridge, gateway, CDR) in about two minutes.
        expect($process->getOutput())
            ->toContain('REGISTER_COUNT=1')
            ->toContain('CALLS=1')
            ->toContain('OUTBOUND_CALLS=1');
    });

    it('fails the series when the SIP smoke preflight does not pass', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        // A broken setup fails the whole series, not one cell: every number
        // after a failed preflight measures a chain that does not work.
        expect($script)->toContain('run_sip_smoke')
            ->toContain('SMOKE-FAILED')
            ->toContain('return 1');
    });

    it('sizes a ladder rung to a sustained call window', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-octane', '--phase=sip', '--cps=5'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // A capacity rung must offer its rate for a whole window (5 calls per
        // second for 60 seconds = 300 calls) on the pause-free capacity
        // scenario. The functional script's 10-call burst finishes in
        // seconds and cannot measure capacity.
        expect($process->getOutput())
            ->toContain('CALLS=300')
            ->toContain('uac-extension-capacity.xml');
    });

    it('lets the runner choose the SIPp scenario on the generator', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/pbx-sipp-validate.sh'));

        // The functional scenario holds a 30-second conversation inside every
        // call; capacity rungs need the pause-free capacity scenario instead.
        expect($script)->toContain('UAC_SCENARIO="${UAC_SCENARIO:-');
    });

    it('records one ladder row per rung', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        // The stop rule (three consecutive rungs under 95 % completion) is
        // applied from a per-rung record: offered rate, calls attempted and
        // calls completed.
        expect($script)->toContain('ladder.csv');
    });

    it('runs the SIP smoke preflight once per tier, not per invocation', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        // The ladder is many short invocations (one per rate). Paying the
        // two-minute preflight every time would double the series; its value
        // is proving the chain once before the tier's first cell.
        expect($script)->toContain('SMOKE-ALREADY-PASSED');
    });

    it('fails the cell when its variant switches did not apply', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        // A cell measured behind a half-applied switch is a plausible but
        // meaningless number. Every phase must stop at the switch failure.
        expect(substr_count($script, 'if ! apply_variant'))->toBe(3);
    });

    it('keeps long ssh sessions alive instead of hanging for hours', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        // A stalled ssh/scp between the orchestrator and a host once froze a
        // rung for 2.5 hours with no output and no failure. Keepalives turn
        // a dead peer into a fast error the cell can record.
        expect($script)->toContain('ServerAliveInterval')
            ->and($script)->toContain('ServerAliveCountMax');
    });

    it('rejects an unknown tier', function (): void {
        $process = matrixRunnerProcess(['--dry-run', '--tier=h9-99c999'], $this->artifactRoot);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('Unknown tier: h9-99c999');
    });

    it('rejects an unknown phase', function (): void {
        $process = matrixRunnerProcess(['--list', '--phase=sideways'], $this->artifactRoot);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('Unknown phase: sideways');
    });

    it('requires an explicit tier before executing cells', function (): void {
        $process = matrixRunnerProcess(['--repeats=1'], $this->artifactRoot);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('Execute mode requires --tier');
    });

    it('refuses to run a tier whose host has not been deployed', function (): void {
        // A local run pointed at a missing application directory stands in for
        // a PBX still carrying an older checkout.
        $process = matrixRunnerProcess(
            ['--tier=h1-1c1g', '--repeats=1'],
            $this->artifactRoot,
            ['PBX_HOST' => 'local', 'REMOTE_APP_DIR' => '/nonexistent-tallpbx-app'],
        );

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('APP-DIR missing')
            ->and($process->getErrorOutput())->toContain('Deploy the current branch');
    });

    it('restores the baseline from a teardown trap and never mutates nftables', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        expect($script)->toContain('trap teardown_tier EXIT INT TERM')
            ->and($script)->toContain('switch_call restore_baseline')
            ->and($script)->toContain('CELL-FAILED');

        foreach (['nft add', 'nft insert', 'nft delete', 'nft flush', 'nft chain', 'nft -f'] as $mutation) {
            expect($script)->not->toContain($mutation);
        }
    });
});
