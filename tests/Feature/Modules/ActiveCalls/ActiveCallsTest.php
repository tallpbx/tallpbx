<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\ActiveCalls\Livewire\ActiveCallsList;

function activeCallsAdminWithPermission(string $permission): Admin
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

function channelsJson(array $rows): string
{
    return json_encode(['header' => ['uuid', 'direction'], 'rows' => $rows, 'row_count' => count($rows)]);
}

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('shows disconnected message when FreeSWITCH ESL is not connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(false);
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveCallsList::class)
        ->assertSee('FreeSWITCH not connected');
});

it('shows active calls when FreeSWITCH is connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('show channels as json')->once()->andReturn(channelsJson([
        ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'cid_num' => '1001', 'cid_name' => 'Alice', 'dest' => '2001', 'state' => 'CS_EXECUTE', 'created_epoch' => (string) (time() - 30), 'context' => 'tenant_1_internal'],
        ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c9', 'cid_num' => '1002', 'cid_name' => 'Bob', 'dest' => '2002', 'state' => 'CS_EXCHANGE_MEDIA', 'created_epoch' => (string) (time() - 15), 'context' => 'tenant_1_internal'],
    ]));
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveCallsList::class)
        ->assertOk()
        ->assertSeeHtml('Alice')
        ->assertSeeHtml('Bob');
});

it('shows empty state when no active calls exist', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    // Real empty response from "show channels as json" has no rows key.
    $mock->shouldReceive('api')->with('show channels as json')->once()->andReturn('{"row_count": 0}');
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveCallsList::class)
        ->assertSee('No active calls');
});

it('handles malformed ESL output without crashing', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    // Simulate unexpected output from a FreeSWITCH version mismatch
    $mock->shouldReceive('api')->with('show channels as json')->once()->andReturn("ERROR: Invalid\n\x00binary\x00garbage");
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveCallsList::class)
        ->assertOk()
        ->assertSee('No active calls'); // Falls back to empty state
});

it('hangs up a listed call when the user holds the permission', function () {
    $admin = activeCallsAdminWithPermission('active-calls.hangup');

    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    // Mount refresh + the action's connectivity probe + the post-action refresh.
    $fs->shouldReceive('isConnected')->times(3)->andReturn(true);
    $fs->shouldReceive('api')->with('show channels as json')->twice()->andReturn(channelsJson([
        ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'cid_num' => '1001', 'cid_name' => 'Alice', 'dest' => '2001', 'state' => 'CS_EXECUTE', 'created_epoch' => (string) (time() - 30), 'context' => 'tenant_1_internal'],
    ]));
    $fs->shouldReceive('api')->with('uuid_kill 6ba7b810-9dad-11d1-80b4-00c04fd430c8')->once()->andReturn('+OK');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($admin, 'admin')
        ->test(ActiveCallsList::class)
        ->call('hangupCall', '6ba7b810-9dad-11d1-80b4-00c04fd430c8')
        ->assertSet('actionMessage', 'Command accepted.');
});

it('denies hangup without the permission, server-side', function () {
    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    // Mount refresh only — the permission gate returns before the action.
    $fs->shouldReceive('isConnected')->once()->andReturn(false);
    $fs->shouldNotReceive('api');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($this->admin, 'admin') // no permission
        ->test(ActiveCallsList::class)
        ->call('hangupCall', '6ba7b810-9dad-11d1-80b4-00c04fd430c8')
        ->assertSet('actionError', 'You do not have permission to hang up calls.');
});

it('shows a friendly error when hanging up while ESL is offline', function () {
    $admin = activeCallsAdminWithPermission('active-calls.hangup');

    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    // Mount refresh + the action's connectivity probe + the post-action refresh.
    $fs->shouldReceive('isConnected')->times(3)->andReturn(false);
    $fs->shouldNotReceive('api');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($admin, 'admin')
        ->test(ActiveCallsList::class)
        ->call('hangupCall', '6ba7b810-9dad-11d1-80b4-00c04fd430c8')
        ->assertSet('actionError', 'FreeSWITCH is not connected.');
});

it('scopes channels to the tenant for a tenant user', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('show channels as json')->once()->andReturn(channelsJson([
        ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'cid_num' => '1001', 'cid_name' => 'Alice', 'dest' => '2001', 'state' => 'CS_EXECUTE', 'created_epoch' => (string) (time() - 30), 'context' => 'tenant_'.$tenant->id.'_internal'],
        ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c9', 'cid_num' => '9001', 'cid_name' => 'Other', 'dest' => '9002', 'state' => 'CS_EXECUTE', 'created_epoch' => (string) (time() - 10), 'context' => 'tenant_999_internal'],
    ]));
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($user, 'web')
        ->test(ActiveCallsList::class)
        ->assertSet('calls', fn (array $calls): bool => count($calls) === 1 && $calls[0]['uuid'] === '6ba7b810-9dad-11d1-80b4-00c04fd430c8');

    app(TenantManager::class)->setTenantId(null);
});

it('shows every channel to an admin', function () {
    Tenant::factory()->create();

    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('show channels as json')->once()->andReturn(channelsJson([
        ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'cid_num' => '1001', 'cid_name' => 'Alice', 'dest' => '2001', 'state' => 'CS_EXECUTE', 'created_epoch' => (string) (time() - 30), 'context' => 'tenant_1_internal'],
        ['uuid' => '6ba7b810-9dad-11d1-80b4-00c04fd430c9', 'cid_num' => '9001', 'cid_name' => 'Other', 'dest' => '9002', 'state' => 'CS_EXECUTE', 'created_epoch' => (string) (time() - 10), 'context' => 'tenant_2_internal'],
    ]));
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveCallsList::class)
        ->assertSet('calls', fn (array $calls): bool => count($calls) === 2);
});
