<?php

declare(strict_types=1);

use App\Support\SystemProcessRunner;

it('runs a command and returns the exit code and output', function (): void {
    $runner = new SystemProcessRunner;

    $result = $runner->run('php -r "echo \'hello\';"');

    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain('hello');
});

it('captures a failing command exit code', function (): void {
    $runner = new SystemProcessRunner;

    $result = $runner->run('php -r "exit(3);"');

    expect($result->exitCode)->toBe(3);
});

it('kills a hung command at the per-step timeout', function (): void {
    config(['git-update.step_timeout_seconds' => 1]);
    $runner = new SystemProcessRunner;

    $result = $runner->run('php -r "sleep(5);"');

    // GNU timeout exits 124 when it kills the command: a hung step fails
    // the pipeline (rollback path) instead of blocking forever.
    expect($result->exitCode)->toBe(124);
});

it('finds and executes binaries in /usr/local/bin such as composer', function (): void {
    $runner = new SystemProcessRunner;

    $result = $runner->run('composer --version');

    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain('Composer');
});
