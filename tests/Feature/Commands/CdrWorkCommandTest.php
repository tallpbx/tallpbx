<?php

declare(strict_types=1);

use App\Services\Cdr\CdrBatchWriter;
use App\Services\Cdr\CdrPayloadParser;
use App\Services\Cdr\CdrRedisIngest;

/*
 * The worker command is deliberately thin: it owns the loop, the idle pause,
 * the memory ceiling, and the backlog warning, while every guarantee about
 * data lives in the digest cycle. These tests bind a spy so the command is
 * exercised without Redis.
 */

/**
 * Spy that records how the worker drove the digest cycle.
 */
class CdrWorkSpy extends CdrRedisIngest
{
    public int $digestCalls = 0;

    public int $chunkUsed = 0;

    public function __construct(public int $digestReturns = 0, public int $pendingReturns = 0)
    {
        parent::__construct(new CdrPayloadParser(sys_get_temp_dir().'/cdr-quarantine-'.uniqid()), new CdrBatchWriter);
    }

    /**
     * Record the chunk size instead of touching the stream.
     */
    public function digest(int $chunkSize = 100): int
    {
        $this->chunkUsed = $chunkSize;
        $this->digestCalls++;

        return $this->digestReturns;
    }

    /**
     * Report the configured backlog.
     */
    public function getPendingCount(): int
    {
        return $this->pendingReturns;
    }
}

it('digests one batch and exits in once mode', function () {
    $spy = new CdrWorkSpy(digestReturns: 3);
    app()->instance(CdrRedisIngest::class, $spy);

    $this->artisan('cdr:work', ['--once' => true, '--chunk' => 50])
        ->expectsOutputToContain('3')
        ->assertSuccessful();

    expect($spy->digestCalls)->toBe(1)
        ->and($spy->chunkUsed)->toBe(50);
});

it('reports an empty batch without failing', function () {
    app()->instance(CdrRedisIngest::class, new CdrWorkSpy(digestReturns: 0, pendingReturns: 0));

    $this->artisan('cdr:work', ['--once' => true])
        ->expectsOutputToContain('0')
        ->assertSuccessful();
});

it('warns when the stream backlog exceeds the threshold', function () {
    app()->instance(CdrRedisIngest::class, new CdrWorkSpy(digestReturns: 0, pendingReturns: 1500));

    $this->artisan('cdr:work', ['--once' => true, '--warn-pending' => 1000])
        ->expectsOutputToContain('1500')
        ->assertSuccessful();
});
