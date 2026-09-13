<?php

declare(strict_types=1);

use App\Services\FreeSwitchServiceInterface;
use App\Support\FreeSwitchRuntimeVersion;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

afterEach(function (): void {
    Mockery::close();
});

function freeSwitchVersionReader(FreeSwitchServiceInterface $freeSwitch): FreeSwitchRuntimeVersion
{
    return new FreeSwitchRuntimeVersion($freeSwitch, new Repository(new ArrayStore));
}

it('formats the connected FreeSWITCH version for footer display', function () {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldReceive('isConnected')->once()->andReturnTrue();
    $freeSwitch->shouldReceive('api')->once()->with('version')->andReturn(
        'FreeSWITCH Version 1.11.2 -dev-29340309922-8020f5f2e7 64bit'
    );

    expect(freeSwitchVersionReader($freeSwitch)->label())->toBe('FreeSWITCH 1.11.2');
});

it('falls back to the product name when ESL is unavailable', function () {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldReceive('isConnected')->once()->andReturnFalse();
    $freeSwitch->shouldNotReceive('api');

    expect(freeSwitchVersionReader($freeSwitch)->label())->toBe('FreeSWITCH');
});

it('parses the version from status-style output too', function () {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);

    $reader = freeSwitchVersionReader($freeSwitch);

    expect($reader->parse('FreeSWITCH (Version 1.11.2 -dev-abc 64bit) is ready'))->toBe('1.11.2');
});

it('trims build metadata from compact version output', function () {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);

    $reader = freeSwitchVersionReader($freeSwitch);

    expect($reader->parse('FreeSWITCH Version 1.11.2-dev-29340309922-8020f5f2e7~64bit'))->toBe('1.11.2');
});
