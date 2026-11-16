<?php

declare(strict_types=1);

use App\Contracts\ProcessRunner;
use App\Services\Appliance\RuntimeServiceReconciler;
use App\Services\GitUpdateService;
use App\Support\ProcessResult;

/*
 * After code, dependencies, schema, and assets change, the resident PHP
 * workers still run the old code. The update is not finished until they are
 * reloaded and confirmed active; a required worker that stays down fails the
 * update with an operator-actionable reason.
 */

/**
 * Build a fake reconciler with the given outcome.
 *
 * @param  list<string>  $failed
 * @param  list<string>  $warnings
 */
function fakeUpdateReconciler(array $failed = [], array $warnings = [], ?string $preflight = null): RuntimeServiceReconciler
{
    $reconciler = Mockery::mock(RuntimeServiceReconciler::class);
    $reconciler->shouldReceive('preflightUpdate')->andReturn($preflight)->byDefault();
    $reconciler->shouldReceive('reconcileAfterUpdate')->andReturn([
        'restarted' => ['freeswitch-listener', 'tallpbx-queue', 'tallpbx-scheduler', 'tallpbx-cdr-worker', 'tallpbx-reverb'],
        'skipped' => [],
        'failed' => $failed,
        'warnings' => $warnings,
        'probes' => ['ok' => [], 'failed' => []],
    ])->byDefault();

    return $reconciler;
}

/**
 * Records every command and reports a clean, up-to-date repository.
 */
final class RecordingUpdateRunner implements ProcessRunner
{
    /** @var list<array<int, string>> */
    public array $commands = [];

    public function run(array $argv, ?string $cwd = null, ?int $timeoutSeconds = null, array $env = []): ProcessResult
    {
        $this->commands[] = $argv;
        $joined = implode(' ', $argv);

        $output = match (true) {
            str_contains($joined, 'status --porcelain') => '',
            str_contains($joined, 'branch -r') => "origin/5.x\n",
            str_contains($joined, 'tag -l') => '',
            str_contains($joined, 'rev-parse --abbrev-ref HEAD') => "5.x\n",
            str_contains($joined, 'rev-parse --verify') => str_repeat('a', 40)."\n",
            default => '',
        };

        return new ProcessResult(0, $output);
    }
}

it('reconciles runtime services after the build and cache clear', function (): void {
    $runner = new RecordingUpdateRunner;
    $result = (new GitUpdateService($runner, fakeUpdateReconciler()))->update('5.x');

    expect($result->success)->toBeTrue()
        ->and(collect($result->steps)->firstWhere('label', 'Reconcile services'))->not->toBeNull();
});

it('fails the update when a required service does not come back', function (): void {
    $runner = new RecordingUpdateRunner;
    $result = (new GitUpdateService($runner, fakeUpdateReconciler(failed: ['tallpbx-cdr-worker'])))->update('5.x');

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toContain('tallpbx-cdr-worker');
});

it('warns but succeeds when only an optional unit does not come back', function (): void {
    $runner = new RecordingUpdateRunner;
    $result = (new GitUpdateService($runner, fakeUpdateReconciler(warnings: ['tallpbx-xml-worker'])))->update('5.x');

    expect($result->success)->toBeTrue()
        ->and(collect($result->steps)->firstWhere('label', 'Reconcile services')['status'])->toBe('warn');
});

it('refuses a web update before changing code when the bounded helper is missing', function (): void {
    $runner = new RecordingUpdateRunner;
    $result = (new GitUpdateService(
        $runner,
        fakeUpdateReconciler(preflight: 'Install /usr/local/sbin/tallpbx-update-workers once as root.'),
    ))->update('5.x');

    $joined = implode("\n", array_map(fn (array $argv): string => implode(' ', $argv), $runner->commands));

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toContain('tallpbx-update-workers')
        // Nothing that mutates code, dependencies, schema, or assets ran.
        ->and($joined)->not->toContain('composer validate')
        ->and($joined)->not->toContain('composer install')
        ->and($joined)->not->toContain('npm ci')
        ->and($joined)->not->toContain('migrate --force')
        ->and($joined)->not->toContain('pull --ff-only');
});
