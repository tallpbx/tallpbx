<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Extensions\Models\Extension;
use Modules\OperatorPanel\Livewire\OperatorPanelIndex;

function operatorPanelAdminWithPermission(string $permission): Admin
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
        ->test(OperatorPanelIndex::class)
        ->assertSee('FreeSWITCH not connected');
});

it('renders the operator panel when connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('show channels as json')->once()->andReturn(json_encode([
        'rows' => [
            ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'cid_num' => '1001', 'cid_name' => 'Alice', 'dest' => '2001', 'state' => 'CS_EXECUTE', 'context' => 'tenant_1_internal'],
        ],
    ]));
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);
    Livewire::actingAs($this->admin, 'admin')
        ->test(OperatorPanelIndex::class)
        ->assertOk()
        ->assertSee('Operator Panel');
});

it('originates a call to a selected extension with the permission', function () {
    $admin = operatorPanelAdminWithPermission('operator-panel.originate');
    // The single-tenant fallback resolves the internal context (tenant_1_internal).
    Tenant::factory()->create(['enabled' => true]);

    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->twice()->andReturn(true);
    $fs->shouldReceive('api')->with('show channels as json')->once()->andReturn('{"row_count": 0}');
    $fs->shouldReceive('api')->with(
        'originate {origination_caller_id_number=1001}user/1001@tenant_1_internal &bridge(user/2001@tenant_1_internal)',
    )->once()->andReturn('+OK');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($admin, 'admin')
        ->test(OperatorPanelIndex::class)
        ->set('selectedExtension', '2001')
        ->set('sourceExtension', '1001')
        ->call('originateCall')
        ->assertSet('actionMessage', 'Command accepted.');
});

it('denies originate without the permission', function () {
    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(false);
    $fs->shouldNotReceive('api')->with(Mockery::pattern('/^originate /'));
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OperatorPanelIndex::class)
        ->set('selectedExtension', '2001')
        ->set('sourceExtension', '1001')
        ->call('originateCall')
        ->assertSet('actionError', 'You do not have permission to originate calls.');
});

it('hangs up a call with the permission', function () {
    $admin = operatorPanelAdminWithPermission('operator-panel.hangup');

    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->times(3)->andReturn(true);
    $fs->shouldReceive('api')->with('show channels as json')->twice()->andReturn(json_encode([
        'rows' => [
            ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'cid_num' => '1001', 'cid_name' => 'Alice', 'dest' => '2001', 'state' => 'CS_EXECUTE', 'context' => 'tenant_1_internal'],
        ],
    ]));
    $fs->shouldReceive('api')->with('uuid_kill 6ba7b810-9dad-11d1-80b4-00c04fd430c8')->once()->andReturn('+OK');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($admin, 'admin')
        ->test(OperatorPanelIndex::class)
        ->call('hangupCall', '6ba7b810-9dad-11d1-80b4-00c04fd430c8')
        ->assertSet('actionMessage', 'Command accepted.');
});

it('denies hangup without the permission', function () {
    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(false);
    $fs->shouldNotReceive('api')->with(Mockery::pattern('/^uuid_kill /'));
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($this->admin, 'admin')
        ->test(OperatorPanelIndex::class)
        ->call('hangupCall', '6ba7b810-9dad-11d1-80b4-00c04fd430c8')
        ->assertSet('actionError', 'You do not have permission to hang up calls.');
});

it('scopes extensions and channels to the tenant for a tenant user', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '1001',
        'display_name' => 'Alice',
        'enabled' => true,
    ]);
    Extension::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'extension_number' => '2001',
        'display_name' => 'Bob',
        'enabled' => true,
    ]);

    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('show channels as json')->once()->andReturn(json_encode([
        'rows' => [
            ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'cid_num' => '1001', 'state' => 'CS_EXECUTE', 'dest' => '2001', 'context' => 'tenant_'.$tenant->id.'_internal'],
            ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c9', 'cid_num' => '2001', 'state' => 'CS_EXECUTE', 'dest' => '1001', 'context' => 'tenant_999_internal'],
        ],
    ]));
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($user, 'web')
        ->test(OperatorPanelIndex::class)
        ->assertSet('activeCalls', fn (array $calls): bool => array_keys($calls) === [1001])
        ->assertSet('extensions', fn ($extensions): bool => $extensions->count() === 1 && $extensions->first()->extension_number === '1001');

    app(TenantManager::class)->setTenantId(null);
});
