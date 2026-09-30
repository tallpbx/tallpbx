<?php

declare(strict_types=1);

use App\Console\Commands\TestCommand;

/**
 * Covers the Pest argument assembly for the app:test browser leg.
 *
 * The browser suite keeps a Chromium instance alive per worker, so the
 * worker count must stay capped in parallel mode and must disappear
 * entirely when the caller requested sequential execution.
 */
it('caps browser workers at two when running in parallel', function (): void {
    $args = TestCommand::browserTestArguments(false);

    expect($args)->toContain('--parallel')
        ->and($args)->toContain('--processes=2')
        ->and($args)->toContain('tests/Browser');
});

it('runs the browser suite without parallel workers when sequential is requested', function (): void {
    $args = TestCommand::browserTestArguments(true);

    expect($args)->not->toContain('--parallel')
        ->and($args)->not->toContain('--processes=2')
        ->and($args)->toContain('tests/Browser')
        ->and($args)->toContain('--compact');
});
