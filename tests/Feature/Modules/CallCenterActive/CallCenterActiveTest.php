<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\CallCenterActive\Livewire\CallCenterActiveList;
use Modules\CallCenters\Models\Queue;
use Modules\Extensions\Models\Extension;

function callCenterAdminWithPermission(string $permission): Admin
{
    $permissionModel = Permission::factory()->create([
        'name' => $permission,
        'module' => strtok($permission, '.'),
    ]);
    $group = Group::factory()->system()->create();
    $group->permissions()->attach($permissionModel);
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($group);

    return $admin;
}

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('shows disconnected message when ESL is not connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(false);
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallCenterActiveList::class)
        ->assertSee('FreeSWITCH not connected');
});

it('shows call center status when connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('callcenter_config queue list')->once()->andReturn("name|strategy|moh_sound\nsupport|longest-idle-agent|tone_stream://%(100,0,600)\n");
    $mock->shouldReceive('api')->with('callcenter_config agent list')->once()->andReturn("agent|status|state|last_state_change\n1000@test|Available|Waiting|0\n");
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallCenterActiveList::class)
        ->assertOk()
        ->assertSeeHtml('support');
});

it('shows empty state', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('callcenter_config queue list')->once()->andReturn('');
    $mock->shouldReceive('api')->with('callcenter_config agent list')->once()->andReturn('');
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallCenterActiveList::class)
        ->assertSee('No active queues');
});

it('parses queues from the callcenter_config queue list output', function () {
    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(true);
    $fs->shouldReceive('api')->with('callcenter_config queue list')->once()->andReturn(
        "name|strategy|moh_sound\nsupport|longest-idle-agent|tone_stream://%(100,0,600)\n"
    );
    $fs->shouldReceive('api')->with('callcenter_config agent list')->once()->andReturn('');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallCenterActiveList::class)
        ->assertSet('queues', fn (array $queues): bool => $queues[0]['name'] === 'support' && $queues[0]['strategy'] === 'longest-idle-agent');
});

it('lists agents from the callcenter_config agent list output', function () {
    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(true);
    $fs->shouldReceive('api')->with('callcenter_config queue list')->once()->andReturn("name|strategy\nsupport|longest-idle-agent\n");
    $fs->shouldReceive('api')->with('callcenter_config agent list')->once()->andReturn(
        "agent|status|state|last_state_change\n1000@test|Available|Waiting|0\n1001@test|On Break|Waiting|0\n"
    );
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallCenterActiveList::class)
        ->assertSet('agents', fn (array $agents): bool => $agents[0]['agent'] === '1000@test' && $agents[1]['status'] === 'On Break');
});

it('pauses an agent with the control permission', function () {
    $admin = callCenterAdminWithPermission('call-center-active.control');

    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->times(3)->andReturn(true);
    $fs->shouldReceive('api')->with('callcenter_config queue list')->twice()->andReturn("name|strategy\nsupport|longest-idle-agent\n");
    $fs->shouldReceive('api')->with('callcenter_config agent list')->twice()->andReturn("agent|status|state|last_state_change\n1000@test|Available|Waiting|0\n");
    $fs->shouldReceive('api')->with('callcenter_config agent pause 1000@test')->once()->andReturn('+OK');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($admin, 'admin')
        ->test(CallCenterActiveList::class)
        ->call('pauseAgent', '1000@test')
        ->assertSet('actionMessage', 'Command accepted.');
});

it('denies agent logout without the permission', function () {
    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(true);
    $fs->shouldReceive('api')->with('callcenter_config queue list')->once()->andReturn("name|strategy\nsupport|longest-idle-agent\n");
    $fs->shouldReceive('api')->with('callcenter_config agent list')->once()->andReturn("agent|status|state|last_state_change\n1000@test|Available|Waiting|0\n");
    $fs->shouldNotReceive('api')->with('callcenter_config agent logout 1000@test');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallCenterActiveList::class)
        ->call('logoutAgent', '1000@test')
        ->assertSet('actionError', 'You do not have permission to control call center agents.');
});

it('scopes queues and agents to the tenant for a tenant user', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '1000',
        'enabled' => true,
    ]);
    Queue::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'support',
        'enabled' => true,
    ]);

    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(true);
    $fs->shouldReceive('api')->with('callcenter_config queue list')->once()->andReturn(
        "name|strategy\nsupport|longest-idle-agent\nbilling|ring-all\n"
    );
    $fs->shouldReceive('api')->with('callcenter_config agent list')->once()->andReturn(
        "agent|status|state|last_state_change\n1000@support|Available|Waiting|0\n5000@billing|Available|Waiting|0\n"
    );
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($user, 'web')
        ->test(CallCenterActiveList::class)
        ->assertSet('queues', fn (array $queues): bool => count($queues) === 1 && $queues[0]['name'] === 'support')
        ->assertSet('agents', fn (array $agents): bool => count($agents) === 1 && $agents[0]['agent'] === '1000@support');

    app(TenantManager::class)->setTenantId(null);
});
