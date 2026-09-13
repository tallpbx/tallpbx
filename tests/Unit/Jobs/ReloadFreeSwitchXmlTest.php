<?php

declare(strict_types=1);

use App\Jobs\ReloadFreeSwitchXml;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Support\Facades\Log;

// ─── ReloadFreeSwitchXml Job ────────────────────────────────────

it('dispatches reloadxml command via ESL service', function () {
    Log::spy();

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')
        ->once()
        ->with('reloadxml')
        ->andReturn('+OK');

    $job = new ReloadFreeSwitchXml('test trigger');
    $job->handle($freeswitch);

    expect(true)->toBeTrue();
});

it('logs warning when ESL returns empty response', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'FreeSWITCH reloadxml returned empty response — ESL may be unavailable.'
            && ($ctx['trigger'] ?? null) === 'test trigger');

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')->once()->with('reloadxml')->andReturn('');

    $job = new ReloadFreeSwitchXml('test trigger');
    $job->handle($freeswitch);
});

it('catches ESL exception and logs error without failing the job', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'FreeSWITCH reloadxml job failed — changes are persisted but XML may be stale.'
            && ($ctx['trigger'] ?? null) === 'test trigger'
            && str_contains($ctx['error'] ?? '', 'Connection refused'));

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')->once()->with('reloadxml')
        ->andThrow(new RuntimeException('Connection refused'));

    $job = new ReloadFreeSwitchXml('test trigger');

    // Job must not throw — database changes are already persisted
    $job->handle($freeswitch);
});

it('does not log secrets in error context', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $msg, array $ctx): bool {
            $contextString = json_encode($ctx);

            // Must not contain any credential-related keys
            return ! str_contains($contextString, 'password')
                && ! str_contains($contextString, 'secret')
                && ! str_contains($contextString, 'token');
        });

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')->once()->with('reloadxml')
        ->andThrow(new RuntimeException('Socket error'));

    $job = new ReloadFreeSwitchXml('gateway updated');
    $job->handle($freeswitch);
});

it('carries a human-readable trigger description for logging', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => ($ctx['trigger'] ?? null) === 'gateway created');

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')->once()->with('reloadxml')->andReturn('+OK');

    $job = new ReloadFreeSwitchXml('gateway created');
    $job->handle($freeswitch);
});
