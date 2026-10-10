<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for the Observe Mode banner counter and Observed Traffic drawer in Security Center.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    $this->seed(SecurityServiceSeeder::class);

    $this->mockStatus = "table inet tallpbx_filter {\n chain input {\n ip saddr @banned_ips counter packets 15 bytes 750 log prefix \"tallpbx-observe:bans \" limit rate 100/minute\n }\n}";

    $this->executor = Mockery::mock(SecurityExecutorInterface::class);
    $this->executor->shouldReceive('ban')->andReturn(true);
    $this->executor->shouldReceive('unban')->andReturn(true);
    $this->executor->shouldReceive('apply')->andReturn(true);
    $this->executor->shouldReceive('status')->andReturnUsing(fn (): string => $this->mockStatus);
    $this->executor->shouldReceive('observeEvents')->andReturn([
        [
            'timestamp' => '2026-10-08 22:44:33',
            'raw_timestamp' => '2026-10-08T22:44:33-07:00',
            'stage' => 'bans',
            'stage_label' => 'Banned Attacker',
            'interface' => 'veth-host',
            'src_ip' => '10.254.254.2',
            'dst_ip' => '10.254.254.1',
            'proto' => 'UDP',
            'spt' => '43032',
            'dpt' => '69',
            'raw' => 'raw-line',
        ],
    ]);
    $this->executor->shouldReceive('flushConntrack')->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $this->executor);

    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-observe.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

it('renders the observe banner with live packet hits when observe mode is active', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('firewallObserveMode', true)
        ->assertSee(__('admin.security_observe_banner_title'))
        ->assertSee(__('admin.security_observe_banner_view_activity'))
        ->assertSee('15 would-be drops');
});

it('opens and closes the observed traffic activity drawer without polling or manual refresh', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('showObserveDrawer', false)
        ->call('openObserveDrawer')
        ->assertSet('showObserveDrawer', true)
        ->assertSee(__('admin.security_observe_drawer_title'))
        ->assertSee('Banned Attackers')
        ->assertSee('10.254.254.2')
        ->assertSee('LIVE ECHO')
        ->assertDontSee('wire:click="$refresh"', false)
        ->assertDontSee('wire:poll', false)
        ->call('closeObserveDrawer')
        ->assertSet('showObserveDrawer', false);
});

it('reacts to ObserveTrafficLogged echo push event while drawer is open', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openObserveDrawer')
        ->assertSet('showObserveDrawer', true);

    // Dispatch echo event simulating Reverb WebSocket push
    $component->dispatch('echo-private:security.alerts,.ObserveTrafficLogged')
        ->assertSet('showObserveDrawer', true)
        ->assertSee('10.254.254.2');
});
