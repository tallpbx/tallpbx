<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Cell Artifact Paths
|--------------------------------------------------------------------------
|
| Two cells of the same variant can run different PHP-FPM pool shapes — a
| worker sweep is exactly that — and they used to share one artifact
| directory, so each run overwrote the previous `cpu.csv`. On 2026-11-09 that
| destroyed per-service CPU for three of four sweep cells; only the last
| survived. The resolved pool shape is therefore part of the directory name,
| so every cell writes beside its siblings instead of on top of them.
|
| These checks run the runner's own path functions in a subshell with the
| matrix resolver stubbed out, rather than matching text: what matters is the
| path a cell actually gets, not how the script spells it.
|
*/

/**
 * Run the runner's path helpers with a stubbed matrix resolver.
 *
 * @param  array<string, string>  $env  Extra environment for the probe.
 * @param  string  $requirements  Requirements the stubbed variant reports.
 */
function probeArtifactPaths(array $env = [], string $requirements = 'fpm:static', string $children = '1'): array
{
    $script = base_path('load-testing/scripts/load-test-matrix.sh');

    // The functions are extracted and evaluated in isolation: sourcing the
    // whole runner would execute it.
    $probe = <<<SH
eval "\$(sed -n '/^variant_dir()/,/^}/p' "\$1")"
eval "\$(sed -n '/^sip_run_dir()/,/^}/p' "\$1")"
eval "\$(sed -n '/^smoke_run_dir()/,/^}/p' "\$1")"
eval "\$(sed -n '/^ablation_run_dir()/,/^}/p' "\$1")"

# Stubs for the matrix resolver the real functions call.
matrix_list() { printf '%s\n' {$requirements}; }
tier_fpm_children() { printf '%s\n' {$children}; }

sip_run_dir h2-2c2g v-fast; echo
smoke_run_dir v-fast; echo
ablation_run_dir v-fast tune-x; echo
SH;

    $process = new Process(['bash', '-c', $probe, 'probe', $script], null, array_merge([
        'ARTIFACT_ROOT' => '/art',
        'SERIES' => 'series-1',
        'SIPP_HOST' => 'local',
        'SIPP_APP_DIR' => '/app',
        'TIER' => 'h2-2c2g',
        'CPS' => '12',
    ], $env));

    $process->run();

    if (! $process->isSuccessful()) {
        throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
    }

    return array_values(array_filter(explode("\n", trim($process->getOutput()))));
}

it('scopes a cell artifact directory to its PHP-FPM pool shape', function (): void {
    expect(probeArtifactPaths())->toBe([
        '/art/h2-2c2g/v-fast-fpm-static1/sip-cps12',
        '/art/h2-2c2g/v-fast-fpm-static1/sip-smoke',
        '/art/h2-2c2g/v-fast-fpm-static1/tune-x',
    ]);
});

it('scopes the generator-side directory identically', function (): void {
    expect(probeArtifactPaths(['SIPP_HOST' => 'sipp.example']))->toBe([
        '/app/storage/app/load-tests/series-1/h2-2c2g/v-fast-fpm-static1/sip-cps12',
        '/app/storage/app/load-tests/series-1/h2-2c2g/v-fast-fpm-static1/sip-smoke',
        '/app/storage/app/load-tests/series-1/h2-2c2g/v-fast-fpm-static1/tune-x',
    ]);
});
