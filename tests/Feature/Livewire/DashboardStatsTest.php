<?php

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FreeSwitchServiceInterface;
use Livewire\Livewire;

it('shows zero counts with no data', function () {
    Livewire::test('dashboard.stats')
        ->assertSee('Total Users')
        ->assertSee('0')
        ->assertSee('Tenants')
        ->assertSee('0');
});

it('shows total users count', function () {
    User::factory()->count(3)->create();

    Livewire::test('dashboard.stats')
        ->assertSee('3');
});

it('shows total tenants count', function () {
    Tenant::factory()->count(2)->create();

    Livewire::test('dashboard.stats')
        ->assertSee('2');
});

it('shows basic cards without auth', function () {
    Livewire::test('dashboard.stats')
        ->assertSee('Total Users')
        ->assertSee('Tenants')
        ->assertDontSee('FreeSWITCH')
        ->assertDontSee('Active Calls');
});

it('does not include wire:poll or a manual refresh button and relies on push updates', function () {
    Livewire::test('dashboard.stats')
        ->assertDontSee('wire:poll')
        ->assertDontSee('wire:target="refreshMonitoringData"', false);
});

it('reactively updates user and tenant counts upon refreshMonitoringData', function () {
    $component = Livewire::test('dashboard.stats')
        ->assertSee('0');

    User::factory()->count(4)->create();
    Tenant::factory()->count(3)->create();

    $component->call('refreshMonitoringData')
        ->assertSee('4')
        ->assertSee('3');
});

it('reactively updates FreeSWITCH connection and active calls count for admins', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $fsMock = Mockery::mock(FreeSwitchServiceInterface::class);
    $fsMock->shouldReceive('isConnected')->andReturn(true);
    $fsMock->shouldReceive('api')->with('show channels as json')->andReturn(json_encode([
        'row_count' => 2,
        'rows' => [
            ['uuid' => 'uuid-1', 'cid_num' => '100', 'dest' => '200', 'created_epoch' => time(), 'state' => 'CS_EXECUTE'],
            ['uuid' => 'uuid-2', 'cid_num' => '101', 'dest' => '201', 'created_epoch' => time(), 'state' => 'CS_EXECUTE'],
        ],
    ]));

    $this->app->instance(FreeSwitchServiceInterface::class, $fsMock);

    Livewire::actingAs($admin, 'admin')
        ->test('dashboard.stats')
        ->call('refreshMonitoringData')
        ->assertSee('Connected')
        ->assertSee('Active Calls')
        ->assertSee('2');
});

it('handles FreeSWITCH disconnection reactively', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $fsMock = Mockery::mock(FreeSwitchServiceInterface::class);
    $fsMock->shouldReceive('isConnected')->andReturn(false);

    $this->app->instance(FreeSwitchServiceInterface::class, $fsMock);

    Livewire::actingAs($admin, 'admin')
        ->test('dashboard.stats')
        ->call('refreshMonitoringData')
        ->assertSee('Disconnected')
        ->assertSee('Active Calls')
        ->assertSee('0');
});

it('broadcasts DashboardStatsUpdated on the dashboard.monitoring channel', function () {
    $event = new \App\Events\Dashboard\DashboardStatsUpdated(source: 'test');

    expect($event->broadcastOn()[0]->name)->toBe('dashboard.monitoring');
    expect($event->broadcastAs())->toBe('DashboardStatsUpdated');
});

it('dispatches DashboardStatsUpdated when model changes occur', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\Dashboard\DashboardStatsUpdated::class]);
    config(['broadcasting.test_broadcasts' => true]);

    $observer = new \App\Observers\DashboardStatsObserver;
    $user = User::factory()->make();
    $observer->created($user);

    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\Dashboard\DashboardStatsUpdated::class, function ($e) {
        return $e->source === 'User';
    });
});

it('dispatches DashboardStatsUpdated when FreeSWITCH channel events occur', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\Dashboard\DashboardStatsUpdated::class]);
    config(['broadcasting.test_broadcasts' => true]);

    $listener = new \App\Listeners\BroadcastDashboardStatsOnFreeSwitchEvent;
    $event = new \App\Events\FreeSwitch\ChannelCreate(
        eventName: 'CHANNEL_CREATE',
        headers: ['Unique-ID' => 'test-uuid'],
        body: '',
    );
    $listener->handle($event);

    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\Dashboard\DashboardStatsUpdated::class, function ($e) {
        return $e->source === 'CHANNEL_CREATE';
    });
});

it('dispatches DashboardStatsUpdated on CHANNEL_DESTROY without dropping events', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\Dashboard\DashboardStatsUpdated::class]);
    config(['broadcasting.test_broadcasts' => true]);

    $listener = new \App\Listeners\BroadcastDashboardStatsOnFreeSwitchEvent;

    $createEvent = new \App\Events\FreeSwitch\ChannelCreate(
        eventName: 'CHANNEL_CREATE',
        headers: ['Unique-ID' => 'test-uuid-1'],
        body: '',
    );
    $destroyEvent = new \App\Events\FreeSwitch\ChannelDestroy(
        eventName: 'CHANNEL_DESTROY',
        headers: ['Unique-ID' => 'test-uuid-1'],
        body: '',
    );

    $listener->handle($createEvent);
    $listener->handle($destroyEvent);

    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\Dashboard\DashboardStatsUpdated::class, 2);
});

