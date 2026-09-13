<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use Illuminate\Support\Facades\Cache;
use Modules\AccessControls\Services\AccessControlServiceInterface;

it('invalidates the acl cache and reloads FreeSWITCH on rule create', function (): void {
    Cache::put('freeswitch:acl', '<stale/>', 60);
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldReceive('connect')->once()->andReturn(true);
    $freeSwitch->shouldReceive('api')->once()->with('reloadacl')->andReturn('+OK');
    $freeSwitch->shouldReceive('disconnect')->once();
    app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

    app(AccessControlServiceInterface::class)->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'providers',
        'action' => 'allow',
        'nodes' => [['type' => 'cidr', 'value' => '10.0.0.0/8']],
    ]);

    expect(Cache::has('freeswitch:acl'))->toBeFalse();
});

it('invalidates and reloads on rule update and delete', function (): void {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldReceive('connect')->andReturn(true);
    $freeSwitch->shouldReceive('api')->with('reloadacl')->andReturn('+OK');
    $freeSwitch->shouldReceive('disconnect');
    app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

    $rule = app(AccessControlServiceInterface::class)->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'providers',
        'action' => 'allow',
    ]);

    foreach ([fn () => app(AccessControlServiceInterface::class)->update($rule, ['description' => 'updated']), fn () => app(AccessControlServiceInterface::class)->delete($rule)] as $operation) {
        Cache::put('freeswitch:acl', '<stale/>', 60);

        $operation();

        expect(Cache::has('freeswitch:acl'))->toBeFalse();
    }
});

it('completes the rule write when FreeSWITCH is unreachable', function (): void {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldReceive('connect')->once()->andReturn(false);
    app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

    $rule = app(AccessControlServiceInterface::class)->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'name' => 'providers',
        'action' => 'allow',
    ]);

    expect($rule->exists)->toBeTrue();
});
