<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ManageGateway;
use App\Jobs\ReloadFreeSwitchXml;
use App\Jobs\ReloadSofiaProfile;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| ReloadFreeSwitchXml Job Tests
|--------------------------------------------------------------------------
*/

describe('reload freeswitch xml job', function (): void {
    it('dispatches reloadxml command via ESL service', function (): void {
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

    it('logs warning when ESL returns empty response', function (): void {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'FreeSWITCH reloadxml returned empty response — ESL may be unavailable.'
                && ($ctx['trigger'] ?? null) === 'test trigger');

        $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $freeswitch->shouldReceive('api')->once()->with('reloadxml')->andReturn('');

        $job = new ReloadFreeSwitchXml('test trigger');
        $job->handle($freeswitch);
    });

    it('catches ESL exception and logs error without failing the job', function (): void {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'FreeSWITCH reloadxml job failed — changes are persisted but XML may be stale.'
                && ($ctx['trigger'] ?? null) === 'test trigger'
                && str_contains($ctx['error'] ?? '', 'Connection refused'));

        $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $freeswitch->shouldReceive('api')->once()->with('reloadxml')
            ->andThrow(new RuntimeException('Connection refused'));

        $job = new ReloadFreeSwitchXml('test trigger');
        $job->handle($freeswitch);
    });

    it('does not log secrets in error context', function (): void {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                $contextString = json_encode($ctx);

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

    it('carries a human-readable trigger description for logging', function (): void {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => ($ctx['trigger'] ?? null) === 'gateway created');

        $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $freeswitch->shouldReceive('api')->once()->with('reloadxml')->andReturn('+OK');

        $job = new ReloadFreeSwitchXml('gateway created');
        $job->handle($freeswitch);
    });
});

/*
|--------------------------------------------------------------------------
| ReloadSofiaProfile Job Tests
|--------------------------------------------------------------------------
*/

describe('reload sofia profile job', function (): void {
    it('dispatches sofia profile rescan command via ESL', function (): void {
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

    it('falls back to reloadxml when rescan returns empty', function (): void {
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

    it('catches ESL exception without failing the job', function (): void {
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

    it('does not log secrets in error context for reload profile', function (): void {
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
});

/*
|--------------------------------------------------------------------------
| ManageGateway Job Tests
|--------------------------------------------------------------------------
*/

describe('manage gateway job', function (): void {
    it('dispatches gateway start command via ESL', function (): void {
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

    it('dispatches gateway kill command via ESL', function (): void {
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

    it('skips unsupported gateway actions without calling ESL', function (): void {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'unsupported')
                && ($ctx['action'] ?? null) === 'restart');

        $freeswitch = Mockery::mock(FreeSwitchServiceInterface::class);
        $freeswitch->shouldNotReceive('api');

        $job = new ManageGateway('external', 'gtw-uuid-789', 'restart', 'bad input');
        $job->handle($freeswitch);
    });

    it('catches ESL exception without failing the gateway job', function (): void {
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
});
