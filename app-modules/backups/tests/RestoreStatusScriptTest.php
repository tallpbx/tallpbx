<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * The status reader must work while the database is being replaced, so it is a
 * bootless PHP script: no autoloader, no container, no queries.
 */

/**
 * Run the bootless status reader against an isolated journal directory.
 */
function runRestoreStatus(string $root): Process
{
    $process = new Process([PHP_BINARY, base_path('scripts/resources/tallpbx-restore-status'), $root]);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/restore-status-'.Str::random(8);
    File::ensureDirectoryExists($this->root, 0700);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('reports a terminal operation and exits successfully', function (): void {
    File::put($this->root.'/op-completed.json', (string) json_encode([
        'phase' => 'completed',
        'phase_at' => '2026-11-15T10:00:00+00:00',
        'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
        'pre_restore_snapshot_path' => '/var/backups/snapshot',
        'failure_reason' => null,
    ]));

    $process = runRestoreStatus($this->root);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())
        ->toContain('op-completed')
        ->toContain('phase=completed')
        ->toContain('archive=/var/lib/tallpbx/backups/x.tar.gz')
        ->toContain('snapshot=/var/backups/snapshot');
});

it('exits non-zero while an operation is still in flight', function (): void {
    File::put($this->root.'/op-running.json', (string) json_encode([
        'phase' => 'importing',
        'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
    ]));

    $process = runRestoreStatus($this->root);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('phase=importing');
});

it('reports an empty journal without failing', function (): void {
    $process = runRestoreStatus($this->root);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('No restore operations recorded');
});

it('flags an unreadable journal entry', function (): void {
    File::put($this->root.'/op-broken.json', '{');

    $process = runRestoreStatus($this->root);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('unreadable');
});
