<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Registrations\Livewire\RegistrationsList;
use Modules\SipAccounts\Models\SipAccount;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('shows disconnected message when FreeSWITCH ESL is not connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(false);
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RegistrationsList::class)
        ->assertSee('FreeSWITCH not connected');
});

it('shows registrations when connected', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    // Real row layout: user@domain;user@domain;contact;expires;0;1;status
    $mock->shouldReceive('api')->with('sofia status profile internal reg')->once()->andReturn(
        "1001@pbx.local;1001@pbx.local;sip:1001@10.0.0.5:5060;300;0;1;registered\n".
        '1002@pbx.local;1002@pbx.local;sip:1002@10.0.0.6:5060;120;0;1;registered'
    );
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RegistrationsList::class)
        ->assertOk()
        ->assertSeeHtml('1001@pbx.local');
});

it('shows empty state', function () {
    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('sofia status profile internal reg')->once()->andReturn('');
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(RegistrationsList::class)
        ->assertSee('No registrations found');
});

it('scopes registrations to the tenant for a tenant user', function () {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'auth_username' => '1001',
        'enabled' => true,
    ]);

    $mock = Mockery::mock(FreeSwitchServiceInterface::class);
    $mock->shouldReceive('isConnected')->once()->andReturn(true);
    $mock->shouldReceive('api')->with('sofia status profile internal reg')->once()->andReturn(
        "1001@pbx.local;1001@pbx.local;sip:1001@10.0.0.5:5060;300;0;1;registered\n".
        '2001@pbx.local;2001@pbx.local;sip:2001@10.0.0.6:5060;120;0;1;registered'
    );
    $this->app->instance(FreeSwitchServiceInterface::class, $mock);

    Livewire::actingAs($user, 'web')
        ->test(RegistrationsList::class)
        ->assertSet('registrations', fn (array $regs): bool => count($regs) === 1 && $regs[0]['user'] === '1001@pbx.local');

    app(TenantManager::class)->setTenantId(null);
});
