<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * The dispatcher claims one request atomically and starts exactly one
 * privileged restore service. It must never replay a claim whose outcome is
 * unknown, never overlap two destructive restores, and never abort the loop
 * because one request failed.
 */

/**
 * Write a fake systemctl that records its arguments and returns scripted results.
 *
 * @param  array{fail_for?: string, active_units?: string, is_active_exit?: int}  $options
 * @return array{0: string, 1: string} The binary path and the call log path.
 */
function fakeSystemctl(string $dir, array $options = []): array
{
    $binary = $dir.'/systemctl';
    $log = $dir.'/systemctl.log';
    $failFor = $options['fail_for'] ?? '';
    $activeUnits = $options['active_units'] ?? '';
    $isActiveExit = (string) ($options['is_active_exit'] ?? 3);

    file_put_contents($binary, <<<SH
#!/bin/bash
printf '%s\n' "\$*" >> "{$log}"
if [ "\$1" = "list-units" ]; then
  if [ -n "{$activeUnits}" ]; then printf '%s\n' "{$activeUnits}"; fi
  exit 0
fi
if [ "\$1" = "start" ]; then
  if [ "\$2" = "{$failFor}" ]; then exit 1; fi
  exit 0
fi
if [ "\$1" = "is-active" ]; then exit {$isActiveExit}; fi
exit 0
SH);
    chmod($binary, 0755);

    return [$binary, $log];
}

/**
 * Run the real dispatcher script against an isolated request directory.
 *
 * @param  array{requests: string, journal: string, systemctl: string, stale?: string}  $paths
 */
function runRestoreDispatcher(array $paths): Process
{
    $process = new Process(
        ['bash', base_path('scripts/resources/tallpbx-restore-dispatch')],
        base_path(),
        [
            'PATH' => (string) getenv('PATH'),
            'TALLPBX_RESTORE_REQUEST_DIR' => $paths['requests'],
            'TALLPBX_RESTORE_JOURNAL_DIR' => $paths['journal'],
            'TALLPBX_SYSTEMCTL' => $paths['systemctl'],
            'TALLPBX_RESTORE_STALE_CLAIM_MINUTES' => $paths['stale'] ?? '30',
        ],
    );
    $process->run();

    return $process;
}

/**
 * Create the isolated directories used by one dispatcher scenario.
 *
 * @return array{requests: string, journal: string, systemctl: string, log: string, dir: string}
 */
function dispatcherPaths(string $scenario): array
{
    $dir = sys_get_temp_dir().'/restore-dispatch-'.$scenario.'-'.Str::random(8);
    $requests = $dir.'/requests';
    $journal = $dir.'/journal';
    File::ensureDirectoryExists($requests, 0770);
    File::ensureDirectoryExists($journal, 0700);

    [$systemctl, $log] = fakeSystemctl($dir);

    return [
        'requests' => $requests,
        'journal' => $journal,
        'systemctl' => $systemctl,
        'log' => $log,
        'dir' => $dir,
    ];
}

it('starts each queued request once and retires its marker', function (): void {
    $paths = dispatcherPaths('start');
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    File::put($paths['requests'].'/'.$first.'.request', '{}');
    File::put($paths['requests'].'/'.$second.'.request', '{}');

    try {
        expect(runRestoreDispatcher($paths)->getExitCode())->toBe(0)
            ->and(glob($paths['requests'].'/*.request'))->toBe([])
            ->and(glob($paths['requests'].'/*.claimed'))->toBe([])
            ->and(file_get_contents($paths['log']))
            ->toContain('start tallpbx-restore@'.$first.'.service')
            ->toContain('start tallpbx-restore@'.$second.'.service');

        file_put_contents($paths['log'], '');

        // A second run must not repeat anything.
        expect(runRestoreDispatcher($paths)->getExitCode())->toBe(0)
            ->and(substr_count((string) file_get_contents($paths['log']), 'start '))->toBe(0);
    } finally {
        File::deleteDirectory($paths['dir']);
    }
});

it('isolates a failing request so the next one still runs', function (): void {
    $paths = dispatcherPaths('isolate');
    $failing = (string) Str::uuid();
    $healthy = (string) Str::uuid();

    [$systemctl, $log] = fakeSystemctl($paths['dir'], ['fail_for' => 'tallpbx-restore@'.$failing.'.service']);
    $paths['systemctl'] = $systemctl;

    File::put($paths['requests'].'/'.$failing.'.request', '{}');
    File::put($paths['requests'].'/'.$healthy.'.request', '{}');

    try {
        expect(runRestoreDispatcher($paths)->getExitCode())->toBe(0)
            ->and(file_exists($paths['requests'].'/'.$failing.'.failed'))->toBeTrue()
            ->and(file_exists($paths['requests'].'/'.$healthy.'.failed'))->toBeFalse()
            ->and(file_get_contents($log))->toContain('start tallpbx-restore@'.$healthy.'.service');
    } finally {
        File::deleteDirectory($paths['dir']);
    }
});

it('accepts a second restore after a completed one', function (): void {
    $paths = dispatcherPaths('retry');
    $earlier = (string) Str::uuid();
    $fresh = (string) Str::uuid();
    File::put($paths['requests'].'/'.$earlier.'.failed', '{}');
    File::put($paths['requests'].'/'.$fresh.'.request', '{}');

    try {
        expect(runRestoreDispatcher($paths)->getExitCode())->toBe(0);

        $calls = (string) file_get_contents($paths['log']);

        expect($calls)->toContain('start tallpbx-restore@'.$fresh.'.service')
            ->and($calls)->not->toContain('start tallpbx-restore@'.$earlier.'.service');
    } finally {
        File::deleteDirectory($paths['dir']);
    }
});

it('recovers a claim abandoned by a crashed dispatcher', function (): void {
    $paths = dispatcherPaths('stale');
    $abandoned = (string) Str::uuid();
    $claim = $paths['requests'].'/'.$abandoned.'.claimed';
    File::put($claim, '{}');
    touch($claim, time() - 7200);

    try {
        expect(runRestoreDispatcher($paths)->getExitCode())->toBe(0)
            ->and(file_exists($claim))->toBeFalse()
            ->and(file_exists($paths['requests'].'/'.$abandoned.'.failed'))->toBeTrue()
            ->and(file_get_contents($paths['log']))->not->toContain('start tallpbx-restore@'.$abandoned.'.service');
    } finally {
        File::deleteDirectory($paths['dir']);
    }
});

it('never starts a second restore while one is running', function (): void {
    $paths = dispatcherPaths('busy');
    $other = (string) Str::uuid();
    $fresh = (string) Str::uuid();
    File::put($paths['requests'].'/'.$fresh.'.request', '{}');

    [$systemctl] = fakeSystemctl($paths['dir'], [
        'active_units' => 'tallpbx-restore@'.$other.'.service loaded active running TallPBX privileged restore operation',
    ]);
    $paths['systemctl'] = $systemctl;

    try {
        expect(runRestoreDispatcher($paths)->getExitCode())->toBe(0)
            ->and(file_exists($paths['requests'].'/'.$fresh.'.request'))->toBeTrue();
    } finally {
        File::deleteDirectory($paths['dir']);
    }
});

it('moves a non-uuid marker out of the request directory', function (): void {
    $paths = dispatcherPaths('rejected');
    File::put($paths['requests'].'/not-a-uuid.request', '{}');

    try {
        expect(runRestoreDispatcher($paths)->getExitCode())->toBe(0)
            ->and(glob($paths['requests'].'/*.request'))->toBe([])
            ->and(file_exists($paths['requests'].'/rejected/not-a-uuid.request'))->toBeTrue();
    } finally {
        File::deleteDirectory($paths['dir']);
    }
});
