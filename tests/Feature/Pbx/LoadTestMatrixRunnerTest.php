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
        foreach ([
            'load-testing/scripts/load-test-matrix.sh',
            'load-testing/scripts/lib/manifest.sh',
            'load-testing/scripts/drivers/run-ladder.sh',
            'load-testing/scripts/drivers/run-tier-comparison.sh',
        ] as $script) {
            $process = new Process(['bash', '-n', base_path($script)], base_path());
            $process->run();

            expect($process->isSuccessful())->toBeTrue($script.': '.$process->getErrorOutput());
        }
    });

    it('schedules both XML handlers for HTTP and calls on every hardware tier', function (): void {
        $process = matrixRunnerProcess(['--list', '--compare-xml-handlers'], $this->artifactRoot);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $rows = preg_split('/\R/', trim($process->getOutput()));
        $tiers = ['h1-1c1g', 'h1-1c2g', 'h2-2c2g', 'h4-4c8g', 'h2d-2c8g', 'h4d-4c16g'];
        $expected = [];

        foreach ($tiers as $tier) {
            foreach (['v-fast-default', 'v-octane'] as $variant) {
                foreach (['xml', 'sip'] as $phase) {
                    $expected[] = [$tier, $variant, $phase];
                }
            }
        }

        expect(array_map(fn (string $row): array => preg_split('/\s+/', trim($row)), $rows))->toBe($expected);
    });

    it('prevents a single handler or diagnostic ablation from replacing a comparison', function (string $option): void {
        $process = matrixRunnerProcess(['--list', '--compare-xml-handlers', $option], $this->artifactRoot);

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain('--compare-xml-handlers');
    })->with(['--variant=v-fast-default', '--phase=ablation']);

    it('requires fresh results from both handlers and keeps testing while either meets the threshold', function (string $mode): void {
        $bin = $this->artifactRoot.'/bin';
        File::ensureDirectoryExists($bin);
        File::put($bin.'/bash', <<<'BASH'
            #!/bin/bash
            set -eu
            [ "$1" = load-testing/scripts/load-test-matrix.sh ]
            [ "$2" = --tier=h4-4c8g ]
            [ "$3" = --compare-xml-handlers ]
            [ "$4" = --phase=sip ]
            rate="${5#--cps=}"
            if [ "$FIXTURE_MODE" = 'runner error' ]; then
                exit 1
            fi
            for variant in v-fast-default v-octane; do
                if [ "$FIXTURE_MODE" = 'missing Octane' ] && [ "$variant" = v-octane ]; then
                    continue
                fi
                dir="$ARTIFACT_ROOT/h4-4c8g/$variant-fpm-dynamic12"
                mkdir -p "$dir"
                if [ ! -f "$dir/ladder.csv" ]; then
                    printf 'recorded_at,cps,successful,failed,label\n' > "$dir/ladder.csv"
                fi
                completed=80
                if [ "$variant" = v-octane ] && [ "$rate" -le 8 ]; then
                    completed=100
                fi
                printf 'fixture,%s,%s,%s,h4-4c8g/%s/sip-cps%s\n' \
                    "$rate" "$completed" "$((100 - completed))" "$variant" "$rate" >> "$dir/ladder.csv"
            done
            BASH);
        chmod($bin.'/bash', 0755);

        $process = new Process(['/bin/bash', base_path('load-testing/scripts/drivers/run-ladder.sh'), '--tier=h4-4c8g'], base_path(), [
            'PATH' => $bin.':'.getenv('PATH'),
            'ARTIFACT_ROOT' => $this->artifactRoot.'/results',
            'PBX_HOST' => 'fixture-pbx.test',
            'SIPP_HOST' => 'fixture-generator.test',
            'FIXTURE_MODE' => $mode,
        ]);
        $process->run();

        if ($mode === 'missing Octane') {
            expect($process->getExitCode())->toBe(1)
                ->and($process->getErrorOutput())->toContain('Missing or ambiguous comparison result at 2 calls/sec');
        } elseif ($mode === 'runner error') {
            expect($process->getExitCode())->toBe(1)
                ->and($process->getErrorOutput())->toContain('Comparison failed at 2 calls/sec');
        } else {
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
                ->and($process->getOutput())->toContain('8 calls/sec: PHP-FPM 80.0%, Octane 100.0%')
                ->toContain('20 calls/sec: PHP-FPM 80.0%, Octane 80.0%')
                ->not->toContain('25 calls/sec');
        }
    })->with(['paired results', 'missing Octane', 'runner error']);

    it('lists every tier and variant for each phase', function (): void {
        $process = matrixRunnerProcess(['--list'], $this->artifactRoot);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $rows = collect(explode("\n", trim($process->getOutput())))
            ->map(fn (string $line): string => implode(' ', preg_split('/\s+/', trim($line))))
            ->sort()
            ->values()
            ->all();

        $tiers = ['h1-1c1g', 'h1-1c2g', 'h2-2c2g', 'h4-4c8g', 'h2d-2c8g', 'h4d-4c16g'];
        $variants = ['v-stock-dynamic', 'v-stock-static', 'v-fast', 'v-fast-default', 'v-octane'];
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

        // Two registrations (the caller and the callee it calls), one
        // extension call, one outbound call: the whole chain (register,
        // bridge, gateway, CDR) in about two minutes. The callee row must be
        // registered here rather than borrowed from an earlier run — without
        // its registration the call dies with SIP 806 USER_NOT_REGISTERED.
        expect($process->getOutput())
            ->toContain('REGISTER_COUNT=2')
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
        // meaningless number. Every phase must stop at the switch failure,
        // and the variant smoke is the fourth gate: a smoke run under the
        // baseline proves nothing about the variant.
        expect(substr_count($script, 'if ! apply_variant'))->toBe(4);
    });

    it('keeps long ssh sessions alive instead of hanging for hours', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        // A stalled ssh/scp between the orchestrator and a host once froze a
        // rung for 2.5 hours with no output and no failure. Keepalives turn
        // a dead peer into a fast error the cell can record.
        expect($script)->toContain('ServerAliveInterval')
            ->and($script)->toContain('ServerAliveCountMax');
    });

    it('measures an ablation under call load, not xml load', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-octane', '--phase=ablation'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // The ESL, Reverb and CDR knobs only do work while calls exist, so an
        // ablation measured with dialplan requests would report zero for the
        // very cost it is meant to expose. Every ablation cell places calls.
        expect($process->getOutput())->toContain('pbx-sipp-validate.sh');
    });

    it('attributes CPU to each service during an ablation cell', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-octane', '--phase=ablation'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // The question a one-change-at-a-time test answers is "what did this
        // knob cost?", so every ablation cell samples per-service CPU over its
        // own window and keeps the sample beside the artifacts — named by the
        // offered rate so a ladder over several rungs never overwrites the
        // earlier samples.
        expect($process->getOutput())->toMatch('/cpuwatch\.sh[^\n]*-cps5-r1-cpu\.csv/');

        // The sample starts before the cell's report creates its directory,
        // so the launcher must create the destination itself — otherwise the
        // shell redirect fails and the sample dies silently.
        expect($process->getOutput())->toMatch('/mkdir -p [^\n]*v-octane[^\n]*cpuwatch\.sh/');
    });

    it('detaches the CPU sampler so the cell is not blocked behind it', function (): void {
        $root = storage_path('framework/testing/cpuwatch-detach-'.uniqid());

        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-fast', '--phase=sip', '--cps=10'],
            $root,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // A sampler left in the foreground of the SSH session holds the channel
        // open until its whole window expires, so SIPp starts minutes late and
        // the sample covers idle time instead of the cell. The launcher must
        // fork it into its own session and give the SSH channel nothing to hold.
        expect($process->getOutput())->toMatch('/\( nohup bash [^\n]*cpuwatch\.sh[^\n]*& \) <\/dev\/null/');
    });

    it('refuses to measure across a freeswitch restart', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        // Two failures wasted a measurement window on 2026-11-08: a switch
        // that was still booting served the smoke, and a restart during the
        // cell turned a completed call into CALL_REJECTED. Readiness before
        // the cell and a PID comparison after it are the guards.
        expect($script)->toContain('wait_for_freeswitch')
            ->and($script)->toContain('FS-RESTARTED')
            ->and($script)->toMatch('/freeswitch_main_pid/');
    });

    it('proves the smoke under each variant, not the baseline', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-fast', '--phase=sip'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // The preflight must run with the variant's switches applied. A smoke
        // run under the baseline proves the baseline, not the variant: a
        // variant that cannot register then only surfaces inside a 60 s cell,
        // after the 120 s register timeout, with no useful evidence.
        expect($process->getOutput())->toContain('v-fast / smoke')
            ->and($process->getOutput())->toContain('pbx-sipp-validate.sh');
    });

    it('attributes CPU to each service during a SIP ladder rung', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-fast', '--phase=sip', '--cps=5'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // A rung's completion says how many calls finished; it does not say
        // where the CPU went. Every rung therefore carries a per-service
        // sample over its own window, named by the offered rate so a ladder
        // never overwrites an earlier rung.
        expect($process->getOutput())->toMatch('/cpuwatch\.sh[^\n]*sip-cps5-cpu\.csv/')
            ->and($process->getOutput())->toContain('pbx-sipp-validate.sh');
    });

    it('restricts the ablation phase to one knob on request', function (): void {
        $process = matrixRunnerProcess(
            ['--dry-run', '--tier=h1-1c1g', '--variant=v-stock-dynamic', '--phase=ablation', '--ablation=fs-db'],
            $this->artifactRoot,
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        // A single-knob run must not pay for every other knob in the matrix:
        // the filter is what keeps a disk-vs-tmpfs ladder to minutes instead
        // of hours of unrelated cells.
        expect($process->getOutput())
            ->toContain('fs-db')
            ->not->toContain('esl-subscriptions')
            ->not->toContain('broadcast-throttle');
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

        expect($script)->toContain('trap teardown_tier EXIT')
            ->and($script)->toContain('switch_call restore_baseline')
            ->and($script)->toContain('CELL-FAILED');

        foreach (['nft add', 'nft insert', 'nft delete', 'nft flush', 'nft chain', 'nft -f'] as $mutation) {
            expect($script)->not->toContain($mutation);
        }
    });

    it('restarts freeswitch instead of unloading mod_xml_curl at runtime', function (): void {
        $lib = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        // Unloading the module while lookups are in flight leaves the XML
        // registry holding freed bindings; the next fetch then dereferences a
        // zeroed binding and the switch dies inside xml_url_fetch.
        expect($lib)->not->toContain('reload mod_xml_curl')
            ->and($lib)->toContain('restart_freeswitch_for_binding_change');
    });

    it('refuses an xml_curl binding that has no gateway-url', function (): void {
        $fixture = (string) tempnam(sys_get_temp_dir(), 'xmlcurl');
        file_put_contents($fixture, <<<'XML'
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="broken">
      <param name="bindings" value="directory"/>
    </binding>
  </bindings>
</configuration>
XML);

        $process = new Process(
            ['bash', base_path('load-testing/scripts/lib/switch.sh'), 'assert_xml_curl_config', $fixture],
            base_path(),
        );
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('gateway-url');

        unlink($fixture);
    });

    it('accepts an xml_curl config whose bindings all carry a gateway-url', function (): void {
        $fixture = (string) tempnam(sys_get_temp_dir(), 'xmlcurl');
        file_put_contents($fixture, <<<'XML'
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="tallpbx-fallback">
      <param name="gateway-url" value="http://127.0.0.1/api/v1/xml-handler?token=abc"/>
      <param name="bindings" value="directory|dialplan"/>
    </binding>
  </bindings>
</configuration>
XML);

        $process = new Process(
            ['bash', base_path('load-testing/scripts/lib/switch.sh'), 'assert_xml_curl_config', $fixture],
            base_path(),
        );
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        unlink($fixture);
    });

    it('reads the octane binding state from a configuration file', function (): void {
        $on = (string) tempnam(sys_get_temp_dir(), 'xmlcurl');
        file_put_contents($on, "<configuration>\n  <bindings>\n    <binding name=\"tallpbx-octane\">\n      <param name=\"gateway-url\" value=\"http://x\"/>\n    </binding>\n  </bindings>\n</configuration>");

        $off = (string) tempnam(sys_get_temp_dir(), 'xmlcurl');
        file_put_contents($off, "<configuration><bindings>\n<!-- <binding name=\"tallpbx-octane\"> -->\n<binding name=\"tallpbx-fallback\"><param name=\"gateway-url\" value=\"http://x\"/></binding>\n</bindings></configuration>");

        foreach ([[$on, 'on'], [$off, 'off']] as [$path, $expected]) {
            $process = new Process(['bash', base_path('load-testing/scripts/lib/switch.sh'), 'octane_binding_state', $path], base_path());
            $process->run();
            expect(trim($process->getOutput()))->toBe($expected);
        }

        unlink($on);
        unlink($off);
    });

    it('isolates each XML backend and restores the original worker state and binding file', function (string $initialState): void {
        $bin = $this->artifactRoot.'/bin';
        $autoload = $this->artifactRoot.'/freeswitch/autoload_configs';
        File::ensureDirectoryExists($bin);
        File::ensureDirectoryExists($autoload);
        File::put($bin.'/systemctl', <<<'BASH'
            #!/bin/bash
            set -eu
            printf '%s\n' "$*" >> "$FIXTURE_DIR/systemctl.log"
            case "$*" in
                'show --property=ActiveState --value tallpbx-xml-worker') cat "$FIXTURE_DIR/worker-state" ;;
                'start tallpbx-xml-worker'|'restart tallpbx-xml-worker') echo active > "$FIXTURE_DIR/worker-state" ;;
                'stop tallpbx-xml-worker') echo inactive > "$FIXTURE_DIR/worker-state" ;;
                'is-active --quiet tallpbx-xml-worker') grep -qx active "$FIXTURE_DIR/worker-state" ;;
                'restart freeswitch'|'reload php'*|'is-active --quiet php'*) exit 0 ;;
                *) exit 1 ;;
            esac
            BASH);
        File::put($bin.'/fs_cli', "#!/bin/bash\nprintf 'UP\n'\n");
        File::put($bin.'/nginx', "#!/bin/bash\nexit 1\n");
        foreach (['systemctl', 'fs_cli', 'nginx'] as $command) {
            chmod($bin.'/'.$command, 0755);
        }

        $file = $autoload.'/xml_curl.conf.xml';
        $original = <<<'XML'
            <configuration name="xml_curl.conf">
              <bindings>
                <binding name="tallpbx-octane">
                  <param name="gateway-url" value="http://127.0.0.1:8090/api/v1/xml-handler"/>
                </binding>
                <binding name="tallpbx-fallback">
                  <param name="gateway-url" value="http://127.0.0.1/api/v1/xml-handler"/>
                </binding>
              </bindings>
            </configuration>
            XML;
        File::put($file, $original);
        chmod($file, 0640);
        File::put($this->artifactRoot.'/worker-state', $initialState."\n");

        $switch = function (string ...$arguments) use ($bin): void {
            $process = new Process(['/bin/bash', base_path('load-testing/scripts/lib/switch.sh'), ...$arguments], base_path(), [
                'PATH' => $bin.':'.getenv('PATH'),
                'APP_DIR' => $this->artifactRoot,
                'FS_CONF_DIR' => $this->artifactRoot.'/freeswitch',
                'SWITCH_STATE_DIR' => $this->artifactRoot.'/switch-state',
                'FIXTURE_DIR' => $this->artifactRoot,
            ]);
            $process->run();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        };

        $switch('apply_xml_worker', 'off');
        $switch('assert_octane_binding', 'off');
        expect(trim(File::get($this->artifactRoot.'/worker-state')))->toBe('inactive');

        $switch('apply_xml_worker', 'only');
        $switch('assert_octane_binding', 'only');
        expect(trim(File::get($this->artifactRoot.'/worker-state')))->toBe('active')
            ->and(File::get($file))->toContain('<!--     <binding name="tallpbx-fallback">');

        $switch('restore_baseline');
        clearstatcache(true, $file);
        expect(File::get($file))->toBe($original)
            ->and(fileperms($file) & 0777)->toBe(0640)
            ->and(trim(File::get($this->artifactRoot.'/worker-state')))->toBe($initialState)
            ->and(substr_count(File::get($this->artifactRoot.'/systemctl.log'), 'restart freeswitch'))->toBe(3);
    })->with(['inactive', 'active']);

    it('leaves the switch alone when the binding already matches', function (): void {
        $lib = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        // A repeated apply must be a no-op: no rewrite, no restart. The early
        // return has to sit before the restart call inside the apply itself,
        // otherwise the shell reaches the restart anyway.
        $start = strpos($lib, 'apply_octane_binding()');
        $already = $start === false ? false : strpos($lib, 'octane XML binding already', $start);
        $restart = $start === false ? false : strpos($lib, 'restart_freeswitch_for_binding_change', $start);

        expect($start)->toBeInt()
            ->and($already)->toBeInt()
            ->and($restart)->toBeInt()
            ->and($already)->toBeLessThan($restart);
    });

    it('leaves php-fpm alone when the pool already matches', function (): void {
        $lib = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        expect($lib)->toContain('php-fpm already');
    });

    it('resolves local_run_dir, ladder.csv, smoke_gate, and ablation cpu.csv through variant_dir', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        expect($script)
            ->toContain('local_run_dir="${ARTIFACT_ROOT}/${TIER}/$(variant_dir "$TIER" "$variant")/sip-cps${CPS}"')
            ->toContain('ladder_file="${ARTIFACT_ROOT}/${TIER}/$(variant_dir "$TIER" "$variant")/ladder.csv"')
            ->toContain('local_run_dir="${ARTIFACT_ROOT}/${TIER}/$(variant_dir "$TIER" "$variant")/${cpu_label}-cps${CPS}-r${repeat}"')
            ->toContain('${REMOTE_ARTIFACT_ROOT}/${TIER}/$(variant_dir "$TIER" "$variant")/${cpu_label}-cps${CPS}-r${repeat}-cpu.csv')
            ->toContain('local_run_dir="${ARTIFACT_ROOT}/${TIER}/$(variant_dir "$TIER" "$variant")/sip-smoke"')
            ->toContain('smoke_gate="${ARTIFACT_ROOT}/${TIER}/$(variant_dir "$TIER" "$variant")/sip-smoke/cdr-gate.txt"');
    });

    it('stages generator scenarios and validation script under load-testing subdirectories on SIPP_HOST', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));

        expect($script)
            ->toContain('${SIPP_APP_DIR}/load-testing/tools')
            ->toContain('${SIPP_APP_DIR}/load-testing/scripts')
            ->toContain('${SIPP_HOST}:${SIPP_APP_DIR}/load-testing/tools/')
            ->toContain('${SIPP_HOST}:${SIPP_APP_DIR}/load-testing/scripts/pbx-sipp-validate.sh');
    });

    it('times both call setup and call teardown in the SIPp capacity scenario and rtt-stats.py', function (): void {
        $scenario = (string) file_get_contents(base_path('load-testing/tools/sipp/uac-extension-capacity.xml'));

        expect($scenario)
            ->toContain('start_rtd="setup"')
            ->toContain('rtd="setup"')
            ->toContain('start_rtd="teardown"')
            ->toContain('rtd="teardown"');

        $traceDir = $this->artifactRoot.'/rtt-fixture';
        File::ensureDirectoryExists($traceDir);
        File::put($traceDir.'/uac-extension-capacity_1_rtt.csv', implode("\n", [
            'Date_ms;response_time_ms;rtd_no',
            '100.0;120.0;setup',
            '150.0;14.0;teardown',
            '200.0;240.0;setup',
            '250.0;26.0;teardown',
            '300.0;360.0;setup',
            '350.0;38.0;teardown',
        ])."\n");

        $setup = new Process(['python3', base_path('load-testing/scripts/tools/rtt-stats.py'), '--rtd=setup', $traceDir], base_path());
        $setup->run();
        $teardown = new Process(['python3', base_path('load-testing/scripts/tools/rtt-stats.py'), '--rtd=teardown', $traceDir], base_path());
        $teardown->run();

        expect($setup->isSuccessful())->toBeTrue($setup->getErrorOutput())
            ->and(trim($setup->getOutput()))->toContain("\t3\t240.0\t240.0\t")
            ->and($teardown->isSuccessful())->toBeTrue($teardown->getErrorOutput())
            ->and(trim($teardown->getOutput()))->toContain("\t3\t26.0\t26.0\t");
    });

    it('records and reports per-call setup and teardown latency in ladder runs', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/load-test-matrix.sh'));
        expect($script)
            ->toContain('recorded_at,cps,successful,failed,label,setup_p50_ms,setup_p95_ms,setup_max_ms,teardown_p50_ms,teardown_p95_ms,teardown_max_ms')
            ->toContain('rtt-stats.py');

        $bin = $this->artifactRoot.'/bin-latency';
        File::ensureDirectoryExists($bin);
        File::put($bin.'/bash', <<<'BASH'
            #!/bin/bash
            set -eu
            rate="${5#--cps=}"
            for variant in v-fast-default v-octane; do
                dir="$ARTIFACT_ROOT/h4-4c8g/$variant-fpm-dynamic12"
                mkdir -p "$dir"
                if [ ! -f "$dir/ladder.csv" ]; then
                    printf 'recorded_at,cps,successful,failed,label,setup_p50_ms,setup_p95_ms,setup_max_ms,teardown_p50_ms,teardown_p95_ms,teardown_max_ms\n' > "$dir/ladder.csv"
                fi
                if [ "$variant" = v-octane ]; then
                    printf 'fixture,%s,80,20,h4-4c8g/%s/sip-cps%s,42.5,91.0,140.0,8.2,15.4,24.0\n' "$rate" "$variant" "$rate" >> "$dir/ladder.csv"
                else
                    printf 'fixture,%s,80,20,h4-4c8g/%s/sip-cps%s,215.0,640.0,980.0,19.5,41.0,72.0\n' "$rate" "$variant" "$rate" >> "$dir/ladder.csv"
                fi
            done
            BASH);
        chmod($bin.'/bash', 0755);

        $process = new Process(['/bin/bash', base_path('load-testing/scripts/drivers/run-ladder.sh'), '--tier=h4-4c8g'], base_path(), [
            'PATH' => $bin.':'.getenv('PATH'),
            'ARTIFACT_ROOT' => $this->artifactRoot.'/results-latency',
            'PBX_HOST' => 'fixture-pbx.test',
            'SIPP_HOST' => 'fixture-generator.test',
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PHP-FPM 80.0% (setup p50=215.0ms p95=640.0ms, teardown p50=19.5ms p95=41.0ms)')
            ->toContain('Octane 80.0% (setup p50=42.5ms p95=91.0ms, teardown p50=8.2ms p95=15.4ms)');
    });

    it('plans the identical standard comparison matrix across every tier under one shared series', function (): void {
        foreach (['h1-1c1g', 'h2-2c2g', 'h4-4c8g'] as $tier) {
            $process = new Process([
                'bash',
                base_path('load-testing/scripts/drivers/run-tier-comparison.sh'),
                '--tier='.$tier,
                '--series=series-20261121',
                '--dry-run',
            ], base_path());
            $process->run();

            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
                ->and($process->getOutput())
                ->toContain('--tier='.$tier.' --compare-xml-handlers --phase=xml --profile=100x5 --ttl=production-60 --section=mixed --repeats=3')
                ->toContain('--tier='.$tier.' --compare-xml-handlers --phase=xml --profile=500x25 --ttl=production-60 --section=mixed --repeats=3')
                ->toContain('--tier='.$tier.' --compare-xml-handlers --phase=xml --profile=1000x25 --ttl=production-60 --section=mixed --repeats=3')
                ->toContain('EXTENSIONS=30 LADDER_SECONDS=60 bash load-testing/scripts/drivers/run-ladder.sh --tier='.$tier)
                ->toContain('EXTENDED=1 MEDIA_FLOW=1')
                ->toContain('consolidate-series.py');
        }
    });

    it('consolidates a uniform multi-tier series and rejects mismatched commits or missing tiers', function (): void {
        $seriesDir = $this->artifactRoot.'/series-20261121';
        $commit = str_repeat('a', 40);
        $tierPool = ['h1-1c1g' => 'dynamic3', 'h2-2c2g' => 'dynamic6', 'h4-4c8g' => 'dynamic12'];

        foreach ($tierPool as $tier => $pool) {
            $tierDir = $seriesDir.'/'.$tier;
            File::ensureDirectoryExists($tierDir);
            File::put($tierDir.'/failures.log', '');
            File::put($tierDir.'/manifest.json', json_encode([
                'recorded_at' => '2026-11-21T19:00:00Z',
                'build' => ['branch' => '5.x', 'commit' => $commit, 'dirty' => false],
                'configuration' => ['freeswitch' => ['runtime_db' => ['mode' => 'sqlite']]],
            ]));

            foreach (['v-fast-default', 'v-octane'] as $variant) {
                $vdir = $tierDir.'/'.$variant.'-fpm-'.$pool;
                File::ensureDirectoryExists($vdir);

                foreach (['100x5' => 100, '500x25' => 500, '1000x25' => 1000] as $profile => $reqs) {
                    for ($r = 1; $r <= 3; $r++) {
                        $isOctane = $variant === 'v-octane';
                        File::put("{$vdir}/{$profile}-production-60-mixed-r{$r}.json", json_encode([
                            'total_requests' => $reqs,
                            'successful_responses' => $reqs,
                            'failed_responses' => 0,
                            'success_rate_percent' => 100.0,
                            'requests_per_second' => $isOctane ? 250.0 : 50.0,
                            'latency_ms' => [
                                'p50' => $isOctane ? 12.0 : 75.0,
                                'p95' => $isOctane ? 24.0 : 140.0,
                                'max' => $isOctane ? 40.0 : 210.0,
                            ],
                        ]));
                    }
                }

                $isOctane = $variant === 'v-octane';
                File::put("{$vdir}/ladder.csv", implode("\n", [
                    'recorded_at,cps,successful,failed,label,setup_p50_ms,setup_p95_ms,setup_max_ms,teardown_p50_ms,teardown_p95_ms,teardown_max_ms',
                    sprintf(
                        '2026-11-21T19:05:00Z,5,300,0,%s/%s/sip-cps5,%s,%s,%s,%s,%s,%s',
                        $tier,
                        $variant,
                        $isOctane ? '45.0' : '220.0',
                        $isOctane ? '95.0' : '610.0',
                        $isOctane ? '150.0' : '900.0',
                        $isOctane ? '9.0' : '18.0',
                        $isOctane ? '16.0' : '38.0',
                        $isOctane ? '25.0' : '60.0',
                    ),
                ])."\n");
            }
        }

        $csvOut = $this->artifactRoot.'/out/consolidated.csv';
        $mdOut = $this->artifactRoot.'/out/report.md';

        $ok = new Process([
            'python3',
            base_path('load-testing/scripts/tools/consolidate-series.py'),
            $seriesDir,
            '--csv-out='.$csvOut,
            '--markdown-out='.$mdOut,
        ], base_path());
        $ok->run();

        expect($ok->isSuccessful())->toBeTrue($ok->getErrorOutput())
            ->and(File::exists($csvOut))->toBeTrue()
            ->and(File::get($csvOut))->toContain('teardown_p95_ms')
            ->and(File::get($mdOut))->toContain('Unified Comparison Series')
            ->and(File::get($mdOut))->toContain('xychart-beta')
            ->and(File::get($mdOut))->toContain('4 vCPU / 8 GB RAM');

        // Mutate one tier's commit SHA: consolidation must reject mixed-build cobbling.
        File::put($seriesDir.'/h4-4c8g/manifest.json', json_encode([
            'recorded_at' => '2026-11-21T19:00:00Z',
            'build' => ['branch' => '5.x', 'commit' => str_repeat('b', 40), 'dirty' => false],
            'configuration' => ['freeswitch' => ['runtime_db' => ['mode' => 'sqlite']]],
        ]));

        $mismatch = new Process([
            'python3',
            base_path('load-testing/scripts/tools/consolidate-series.py'),
            $seriesDir,
        ], base_path());
        $mismatch->run();

        expect($mismatch->isSuccessful())->toBeFalse()
            ->and($mismatch->getErrorOutput())->toContain('different git commits');
    });
});
