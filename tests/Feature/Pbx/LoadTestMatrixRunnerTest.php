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
        ['bash', base_path('scripts/load-test-matrix.sh'), ...$arguments],
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
        foreach (['scripts/load-test-matrix.sh', 'scripts/load-test/lib/manifest.sh'] as $script) {
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
        $script = (string) file_get_contents(base_path('scripts/load-test-matrix.sh'));

        expect($script)->toContain('trap teardown_tier EXIT INT TERM')
            ->and($script)->toContain('switch_call restore_baseline')
            ->and($script)->toContain('CELL-FAILED');

        foreach (['nft add', 'nft insert', 'nft delete', 'nft flush', 'nft chain', 'nft -f'] as $mutation) {
            expect($script)->not->toContain($mutation);
        }
    });
});
