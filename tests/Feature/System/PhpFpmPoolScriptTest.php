<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * Guards the shared PHP-FPM pool reconciler.
 *
 * scripts/resources/php-fpm-pool.sh is the single implementation of the
 * memory -> worker-ceiling table. The installer sources it and
 * `appliance:sync-host` executes it, so both paths must write the same six
 * directives from the same numbers.
 *
 * These are behavioural tests: the script is pointed at a throwaway tree with
 * FPM_POOL_BASE / FPM_POOL_MEMINFO, and at a stub validator through
 * FPM_POOL_PHP_FPM_BIN, so nothing under /etc is read or written and no real
 * PHP-FPM unit is signalled.
 */

/**
 * Build a throwaway PHP tree that looks like /etc/php for one version.
 *
 * The pool file starts in Debian's stock shape (dynamic, five workers, no
 * max_requests) so every assertion also proves that the commented defaults are
 * replaced rather than left behind.
 *
 * @return array{root: string, base: string, pool: string, meminfo: string, validator: string}
 */
function fpmPoolFixture(string $version = '8.5', int $totalMb = 1024, bool $validatorPasses = true): array
{
    $root = sys_get_temp_dir().'/tallpbx-fpm-'.bin2hex(random_bytes(6));
    $poolDir = $root.'/php/'.$version.'/fpm/pool.d';
    mkdir($poolDir, 0775, true);

    $pool = $poolDir.'/www.conf';
    file_put_contents($pool, implode(PHP_EOL, [
        '; Debian stock pool',
        'pm = dynamic',
        'pm.max_children = 5',
        'pm.start_servers = 2',
        'pm.min_spare_servers = 1',
        'pm.max_spare_servers = 3',
        '; pm.max_requests = 500',
        '',
    ]));

    // One kB entry is enough: the script only reads MemTotal.
    $meminfo = $root.'/meminfo';
    file_put_contents($meminfo, sprintf("MemTotal:       %d kB\nMemFree: 1024 kB\n", $totalMb * 1024));

    // Stand-in for `php-fpm<version> -t`; the real binary would validate the
    // host's own /etc tree instead of the fixture.
    $validator = $root.'/php-fpm-stub';
    file_put_contents($validator, "#!/bin/sh\nexit ".($validatorPasses ? '0' : '1')."\n");
    chmod($validator, 0755);

    $GLOBALS['tallpbxFpmFixtures'][] = $root;

    return [
        'root' => $root,
        'base' => $root.'/php',
        'pool' => $pool,
        'meminfo' => $meminfo,
        'validator' => $validator,
    ];
}

/**
 * Run the reconciler against a fixture tree and return the finished process.
 *
 * @param  array<string, string>  $env  extra environment values for the script
 * @param  array<int, string>  $args  script arguments, reload suppressed by default
 */
function runFpmPoolScript(array $fixture, array $env = [], array $args = ['--reload=none']): Process
{
    $process = new Process(
        array_merge(['bash', base_path('scripts/resources/php-fpm-pool.sh')], $args),
        base_path(),
        array_merge([
            'FPM_POOL_BASE' => $fixture['base'],
            'FPM_POOL_MEMINFO' => $fixture['meminfo'],
            'FPM_POOL_PHP_FPM_BIN' => $fixture['validator'],
            'FPM_POOL_MAX_CHILDREN_OVERRIDE' => '',
        ], $env),
    );

    $process->run();

    return $process;
}

afterEach(function (): void {
    foreach ($GLOBALS['tallpbxFpmFixtures'] ?? [] as $directory) {
        File::deleteDirectory($directory);
    }

    $GLOBALS['tallpbxFpmFixtures'] = [];
});

it('writes the full documented shape for the minimal tier', function (): void {
    $fixture = fpmPoolFixture(version: '8.5', totalMb: 1024);

    $process = runFpmPoolScript($fixture);

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('max_children=3')
        ->and($process->getOutput())->toContain('changed=1')
        ->and($process->getOutput())->toContain('config=ok')
        ->and($process->getOutput())->toContain('reload=skipped');

    $pool = (string) file_get_contents($fixture['pool']);

    // All six directives, including the two the old PHP block never touched.
    expect($pool)->toContain('pm = dynamic')
        ->and($pool)->toContain('pm.max_children = 3')
        ->and($pool)->toContain('pm.start_servers = 1')
        ->and($pool)->toContain('pm.min_spare_servers = 1')
        ->and($pool)->toContain('pm.max_spare_servers = 2')
        ->and($pool)->toContain('pm.max_requests = 10000')
        ->and($pool)->not->toContain('; pm.max_requests = 500');
});

it('follows the memory tier table', function (int $totalMb, string $expected): void {
    $fixture = fpmPoolFixture(totalMb: $totalMb);

    runFpmPoolScript($fixture);

    expect((string) file_get_contents($fixture['pool']))
        ->toContain("pm.max_children = {$expected}");
})->with([
    'minimal tier' => [1024, '3'],
    'two gigabyte tier' => [2048, '6'],
    'four gigabyte tier' => [3921, '12'],
]);

it('honours the administrator override instead of the memory tier', function (): void {
    // A 4 GB host pinned to the tier a 1 GB benchmark was taken on.
    $fixture = fpmPoolFixture(totalMb: 3921);

    $process = runFpmPoolScript($fixture, ['FPM_POOL_MAX_CHILDREN_OVERRIDE' => '3']);

    expect($process->isSuccessful())->toBeTrue()
        ->and((string) file_get_contents($fixture['pool']))->toContain('pm.max_children = 3');
});

it('ignores an override that is not a positive whole number', function (): void {
    $fixture = fpmPoolFixture(totalMb: 1024);

    $process = runFpmPoolScript($fixture, ['FPM_POOL_MAX_CHILDREN_OVERRIDE' => 'zero']);

    // The typo is reported, and the measured ceiling still applies.
    expect($process->getErrorOutput())->toContain("ignoring PHP_FPM_MAX_CHILDREN='zero'")
        ->and((string) file_get_contents($fixture['pool']))->toContain('pm.max_children = 3');
});

it('leaves an already-correct pool byte-identical on a second run', function (): void {
    $fixture = fpmPoolFixture(totalMb: 1024);

    runFpmPoolScript($fixture);
    $first = md5_file($fixture['pool']);

    $second = runFpmPoolScript($fixture);

    expect($second->isSuccessful())->toBeTrue()
        ->and($second->getOutput())->toContain('changed=0')
        ->and(md5_file($fixture['pool']))->toBe($first);
});

it('restores the previous pool when PHP rejects the rewrite', function (): void {
    $fixture = fpmPoolFixture(totalMb: 1024, validatorPasses: false);
    $original = (string) file_get_contents($fixture['pool']);

    $process = runFpmPoolScript($fixture);

    // A pool that fails `php-fpm -t` would stop the unit from starting, so the
    // previous file must come back and the run must report the failure.
    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('config=fail')
        ->and((string) file_get_contents($fixture['pool']))->toBe($original);

    // No staging files are left next to a pool that is in production use.
    expect(glob($fixture['pool'].'.tallpbx-*'))->toBeEmpty();
});

it('derives the PHP version from the pool path', function (): void {
    $fixture = fpmPoolFixture(version: '8.4', totalMb: 2048);

    $process = runFpmPoolScript($fixture);

    expect($process->getOutput())->toContain('version=8.4');
});

it('reports a host with no pool files instead of failing silently', function (): void {
    $fixture = fpmPoolFixture();
    File::deleteDirectory($fixture['base']);

    $process = runFpmPoolScript($fixture);

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('reason=no-pool-files')
        ->and($process->getErrorOutput())->toContain('no PHP-FPM pool files found');
});

it('stays inert when sourced by the installer', function (): void {
    // scripts/resources/php.sh sources this file and calls fpm_pool_main
    // itself; without the guard the source step would rewrite the live pool.
    $script = (string) file_get_contents(base_path('scripts/resources/php-fpm-pool.sh'));

    expect($script)->toContain('if [ "${BASH_SOURCE[0]}" = "$0" ]; then')
        ->and($script)->toContain('unit="php${version}-fpm"');
});
