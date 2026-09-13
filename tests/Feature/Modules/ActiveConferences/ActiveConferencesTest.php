<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\ActiveConferences\Livewire\ActiveConferencesList;
use Modules\Extensions\Models\Extension;

function activeConferencesAdminWithPermission(string $permission): Admin
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

it('shows disconnected message when FreeSWITCH ESL is not connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(false);
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveConferencesList::class)
        ->assertSee('FreeSWITCH not connected');
});

it('shows active conferences when connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('conference list')->once()->andReturn("Conference conf-1 (members: 3) running\nConference conf-2 (members: 1) locked");
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveConferencesList::class)
        ->assertOk()
        ->assertSeeHtml('conf-1');
});

it('shows empty state', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('conference list')->once()->andReturn('');
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveConferencesList::class)
        ->assertSee('No active conferences');
});

it('parses conference members from the conference list output', function () {
    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(true);
    // FreeSWITCH member line format: member_id;uuid;caller_id;conf_name;flags...
    $fs->shouldReceive('api')->with('conference list')->once()->andReturn(
        "Conference 3000 (members: 2) running\n".
        "1;6ba7b810-9dad-11d1-80b4-00c04fd430c8;1001;3000;listen;talk\n".
        "2;6ba7b810-9dad-11d1-80b4-00c04fd430c9;1002;3000;listen;talk\n"
    );
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveConferencesList::class)
        ->assertSet('conferences', fn (array $confs): bool => $confs[0]['members_list'][1]['uuid'] === '6ba7b810-9dad-11d1-80b4-00c04fd430c9');
});

it('mutes a conference member with the permission', function () {
    $admin = activeConferencesAdminWithPermission('active-conferences.mute');

    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->times(3)->andReturn(true);
    $fs->shouldReceive('api')->with('conference list')->twice()->andReturn(
        "Conference 3000 (members: 1) running\n1;6ba7b810-9dad-11d1-80b4-00c04fd430c8;1001;3000;listen;talk\n"
    );
    $fs->shouldReceive('api')->with('conference 3000 mute 1 on')->once()->andReturn('+OK');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($admin, 'admin')
        ->test(ActiveConferencesList::class)
        ->call('muteMember', '3000', '1')
        ->assertSet('actionMessage', 'Command accepted.');
});

it('denies kick without the permission', function () {
    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(true);
    $fs->shouldReceive('api')->with('conference list')->once()->andReturn(
        "Conference 3000 (members: 1) running\n1;6ba7b810-9dad-11d1-80b4-00c04fd430c8;1001;3000;listen;talk\n"
    );
    $fs->shouldNotReceive('api')->with('conference 3000 kick 1');
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ActiveConferencesList::class)
        ->call('kickMember', '3000', '1')
        ->assertSet('actionError', 'You do not have permission to kick conference members.');
});

it('scopes conferences to the tenant for a tenant user', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '1001',
        'enabled' => true,
    ]);

    $fs = Mockery::mock(FreeSwitchServiceInterface::class);
    $fs->shouldReceive('isConnected')->once()->andReturn(true);
    $fs->shouldReceive('api')->with('conference list')->once()->andReturn(
        "Conference 3000 (members: 1) running\n1;6ba7b810-9dad-11d1-80b4-00c04fd430c8;1001;3000;listen;talk\n".
        "Conference 4000 (members: 1) running\n1;6ba7b810-9dad-11d1-80b4-00c04fd430c9;2001;4000;listen;talk\n"
    );
    $this->app->instance(FreeSwitchServiceInterface::class, $fs);

    Livewire::actingAs($user, 'web')
        ->test(ActiveConferencesList::class)
        ->assertSet('conferences', fn (array $confs): bool => count($confs) === 1 && $confs[0]['name'] === '3000');

    app(TenantManager::class)->setTenantId(null);
});
