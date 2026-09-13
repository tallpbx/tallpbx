<?php

declare(strict_types=1);

use App\Jobs\ManageGateway;
use App\Jobs\ReloadSofiaProfile;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Support\Facades\Log;

// ─── ReloadSofiaProfile ────────────────────────────────────────

it('dispatches sofia profile rescan command via ESL', function () {
    Log::spy();

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')
        ->once()
        ->with('sofia profile external rescan')
        ->andReturn('+OK');

    $job = new ReloadSofiaProfile('external', 'gateway updated');
    $job->handle($freeswitch);

    expect(true)->toBeTrue();
});

it('falls back to reloadxml when rescan returns empty', function () {
    Log::spy();

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')
        ->once()
        ->with('sofia profile internal rescan')
        ->andReturn('');
    $freeswitch->shouldReceive('api')
        ->once()
        ->with('reloadxml')
        ->andReturn('+OK');

    $job = new ReloadSofiaProfile('internal', 'profile updated');
    $job->handle($freeswitch);
});

it('catches ESL exception without failing the job', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'Sofia profile rescan failed')
            && ($ctx['profile'] ?? null) === 'external');

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')->once()
        ->andThrow(new RuntimeException('Connection refused'));

    $job = new ReloadSofiaProfile('external', 'test');
    $job->handle($freeswitch);
});

it('does not log secrets in error context', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $msg, array $ctx): bool {
            $contextString = json_encode($ctx);

            return ! str_contains($contextString, 'password')
                && ! str_contains($contextString, 'secret')
                && ! str_contains($contextString, 'token');
        });

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')->once()
        ->andThrow(new RuntimeException('Socket error'));

    $job = new ReloadSofiaProfile('external', 'gateway created');
    $job->handle($freeswitch);
});

// ─── ManageGateway ──────────────────────────────────────────────

it('dispatches gateway start command via ESL', function () {
    Log::spy();

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')
        ->once()
        ->with('sofia profile external startgw gtw-uuid-123')
        ->andReturn('+OK');

    $job = new ManageGateway('external', 'gtw-uuid-123', ManageGateway::ACTION_START, 'gateway enabled');
    $job->handle($freeswitch);

    expect(true)->toBeTrue();
});

it('dispatches gateway kill command via ESL', function () {
    Log::spy();

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')
        ->once()
        ->with('sofia profile external killgw gtw-uuid-456')
        ->andReturn('+OK');

    $job = new ManageGateway('external', 'gtw-uuid-456', ManageGateway::ACTION_KILL, 'gateway disabled');
    $job->handle($freeswitch);

    expect(true)->toBeTrue();
});

it('skips unsupported gateway actions without calling ESL', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'unsupported')
            && ($ctx['action'] ?? null) === 'restart');

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldNotReceive('api');

    $job = new ManageGateway('external', 'gtw-uuid-789', 'restart', 'bad input');
    $job->handle($freeswitch);
});

it('catches ESL exception without failing the gateway job', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'Sofia gateway action failed')
            && ($ctx['gateway'] ?? null) === 'gtw-err');

    $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeswitch->shouldReceive('api')->once()
        ->andThrow(new RuntimeException('Connection refused'));

    $job = new ManageGateway('external', 'gtw-err', ManageGateway::ACTION_START, 'test');
    $job->handle($freeswitch);
});
