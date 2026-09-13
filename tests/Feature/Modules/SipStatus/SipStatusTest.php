<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Services\FreeSwitchServiceInterface;
use Livewire\Livewire;
use Modules\SipStatus\Livewire\SipStatusList;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('shows disconnected message when FreeSWITCH ESL is not connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(false);
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipStatusList::class)
        ->assertSee('FreeSWITCH not connected');
});

it('shows SIP status when connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('sofia status')->once()->andReturn("internal: RUNNING (0)\nexternal: RUNNING (0)");
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipStatusList::class)
        ->assertOk()
        ->assertSeeHtml('internal');
});

it('shows empty state when no profiles', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('sofia status')->once()->andReturn('');
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipStatusList::class)
        ->assertSee('No SIP profiles found');
});
