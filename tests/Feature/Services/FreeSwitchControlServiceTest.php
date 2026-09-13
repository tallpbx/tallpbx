<?php

declare(strict_types=1);

use App\Services\FreeSwitchControlService;
use App\Services\FreeSwitchServiceInterface;

function controlService(FreeSwitchServiceInterface $mock): FreeSwitchControlService
{
    app()->instance(FreeSwitchServiceInterface::class, $mock);

    return new FreeSwitchControlService($mock);
}

it('hangs up a channel with the exact uuid_kill command', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->once()->with('uuid_kill 6ba7b810-9dad-11d1-80b4-00c04fd430c8')->andReturn('+OK');

    $result = controlService($mock)->hangup('6ba7b810-9dad-11d1-80b4-00c04fd430c8', ['6ba7b810-9dad-11d1-80b4-00c04fd430c8']);

    expect($result['success'])->toBeTrue();
});

it('transfers a channel with the exact uuid_transfer command', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->once()->with('uuid_transfer 6ba7b810-9dad-11d1-80b4-00c04fd430c8 2001')->andReturn('+OK');

    $result = controlService($mock)->transfer('6ba7b810-9dad-11d1-80b4-00c04fd430c8', '2001', ['6ba7b810-9dad-11d1-80b4-00c04fd430c8']);

    expect($result['success'])->toBeTrue();
});

it('rejects a uuid not present in the current channel list', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    // Connectivity is probed first; validation then fails before any api call.
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldNotReceive('api');

    $result = controlService($mock)->hangup('6ba7b810-9dad-11d1-80b4-00c04fd430c8', ['other-uuid']);

    expect($result['success'])->toBeFalse();
});

it('strips hostile characters from uuids before building the command', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    // The hostile suffix is stripped and the clean uuid matches the list.
    $mock->shouldReceive('api')->once()->with('uuid_kill 6ba7b810-9dad-11d1-80b4-00c04fd430c8')->andReturn('+OK');

    $result = controlService($mock)->hangup("6ba7b810-9dad-11d1-80b4-00c04fd430c8';<>", ['6ba7b810-9dad-11d1-80b4-00c04fd430c8']);

    expect($result['success'])->toBeTrue();
});

it('rejects a hostile transfer destination', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    // Connectivity is probed first; validation then fails before any api call.
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldNotReceive('api');

    $result = controlService($mock)->transfer('6ba7b810-9dad-11d1-80b4-00c04fd430c8', "2001';id", ['6ba7b810-9dad-11d1-80b4-00c04fd430c8']);

    expect($result['success'])->toBeFalse();
});

it('mutes and unmutes a conference member', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->twice()->andReturn(true);
    $mock->shouldReceive('api')->once()->with('conference 3000 mute 1 on')->andReturn('+OK');
    $mock->shouldReceive('api')->once()->with('conference 3000 mute 1 off')->andReturn('+OK');

    expect(controlService($mock)->conferenceMute('3000', '1', true)['success'])->toBeTrue();
    expect(controlService($mock)->conferenceMute('3000', '1', false)['success'])->toBeTrue();
});

it('kicks a conference member', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->once()->with('conference 3000 kick 1')->andReturn('+OK');

    $result = controlService($mock)->conferenceKick('3000', '1');

    expect($result['success'])->toBeTrue();
});

it('pauses, unpauses, and logs out a call center agent', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->times(3)->andReturn(true);
    $mock->shouldReceive('api')->once()->with('callcenter_config agent pause 1000@test')->andReturn('+OK');
    $mock->shouldReceive('api')->once()->with('callcenter_config agent unpause 1000@test')->andReturn('+OK');
    $mock->shouldReceive('api')->once()->with('callcenter_config agent logout 1000@test')->andReturn('+OK');

    expect(controlService($mock)->agentPause('1000@test')['success'])->toBeTrue();
    expect(controlService($mock)->agentUnpause('1000@test')['success'])->toBeTrue();
    expect(controlService($mock)->agentLogout('1000@test')['success'])->toBeTrue();
});

it('originates a call from the operator panel', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    // FusionPBX pattern: originate the source leg and bridge to the destination.
    $mock->shouldReceive('api')->once()->with(
        'originate {origination_caller_id_number=1001}user/1001@tenant_1_internal &bridge(user/2001@tenant_1_internal)',
    )->andReturn('+OK');

    $result = controlService($mock)->originate('1001', '2001', 'tenant_1_internal');

    expect($result['success'])->toBeTrue();
});

it('fails cleanly when ESL is not connected', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(false);
    $mock->shouldNotReceive('api');

    $result = controlService($mock)->hangup('6ba7b810-9dad-11d1-80b4-00c04fd430c8', ['6ba7b810-9dad-11d1-80b4-00c04fd430c8']);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('not connected');
});

it('treats empty and error api responses as failures', function (): void {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->twice()->andReturn(true);
    $mock->shouldReceive('api')->once()->with('uuid_kill a')->andReturn('');
    $mock->shouldReceive('api')->once()->with('uuid_kill b')->andReturn('-ERR no such channel');

    expect(controlService($mock)->hangup('a', ['a'])['success'])->toBeFalse();
    expect(controlService($mock)->hangup('b', ['b'])['success'])->toBeFalse();
});
