<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps the cache sweep runner syntactically valid', function (): void {
    $script = base_path('scripts/run-cache-sweep.sh');
    $process = new Process(['bash', '-n', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('defines the 5 standard cache sweep tiers in the runner', function (): void {
    $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

    expect($script)->toContain('cold-baseline')
        ->and($script)->toContain('contributor-only')
        ->and($script)->toContain('prod-baseline-100')
        ->and($script)->toContain('call-center-ttl30')
        ->and($script)->toContain('memory-hit-ceiling');
});

it('inspects Redis stats and calculates keyspace hit rates', function (): void {
    $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

    expect($script)->toContain('redis-cli info stats')
        ->and($script)->toContain('keyspace_hits')
        ->and($script)->toContain('keyspace_misses')
        ->and($script)->toContain('Hit Rate');
});

it('safely restores production cache defaults on exit', function (): void {
    $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

    expect($script)->toContain('trap restore_defaults EXIT')
        ->and($script)->toContain('XML_CACHE_TTL=5')
        ->and($script)->toContain('FREESWITCH_XML_HANDLER_CACHE_TTL=5');
});

it('formats output with average latency instead of percentiles', function (): void {
    $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

    expect($script)->toContain('Avg (ms)')
        ->and($script)->toContain('"average"')
        ->and($script)->not->toContain('p50');
});
