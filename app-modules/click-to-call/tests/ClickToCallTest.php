<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use Livewire\Livewire;
use Modules\ClickToCall\Livewire\ClickToCallForm;
use Modules\Extensions\Models\Extension;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '1001',
    ]);
});

it('renders the click to call form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ClickToCallForm::class)
        ->assertOk()
        ->assertSee('Click to Call');
});

it('shows a safe page warning when FreeSWITCH is not connected', function () {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldReceive('isConnected')->once()->andReturnFalse();
    app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ClickToCallForm::class)
        ->set('phoneNumber', '+15551234567')
        ->set('extension', '1001')
        ->call('initiateCall')
        ->assertSet('operationalMessageType', 'warning')
        ->assertSet('operationalMessage', 'FreeSWITCH not connected. Live data is unavailable.');
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ClickToCallForm::class)
        ->call('initiateCall')
        ->assertHasErrors(['phoneNumber', 'extension']);
});

it('originates a call successfully through FreeSwitchControlService when connected', function () {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldReceive('isConnected')->andReturnTrue();
    $freeSwitch->shouldReceive('api')
        ->once()
        ->with("originate {origination_caller_id_number=1001}user/1001@tenant_{$this->tenant->id}_internal &bridge(loopback/+15551234567/tenant_{$this->tenant->id}_internal)")
        ->andReturn('+OK');
    app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ClickToCallForm::class)
        ->set('phoneNumber', '+15551234567')
        ->set('extension', '1001')
        ->call('initiateCall')
        ->assertSet('operationalMessageType', 'success')
        ->assertSet('operationalMessage', __('admin.click_to_call_initiated'));
});

it('rejects phone numbers containing newline injection attempts', function (string $invalidPhone) {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldNotReceive('api');
    $freeSwitch->shouldNotReceive('bgapi');
    app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ClickToCallForm::class)
        ->set('phoneNumber', $invalidPhone)
        ->set('extension', '1001')
        ->call('initiateCall')
        ->assertHasErrors(['phoneNumber']);
})->with([
    "+15551234567\napi shutdown",
    "+15551234567\r\noriginate ...",
    '123; rm -rf /',
    'invalid-letters',
    '12', // too short (min 3)
]);

it('rejects extensions that do not belong to the tenant or contain newlines', function (string $invalidExtension) {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldNotReceive('api');
    $freeSwitch->shouldNotReceive('bgapi');
    app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ClickToCallForm::class)
        ->set('phoneNumber', '+15551234567')
        ->set('extension', $invalidExtension)
        ->call('initiateCall')
        ->assertHasErrors(['extension']);
})->with([
    '9999', // does not exist in tenant
    "1001\napi shutdown",
    "1001\r",
    "1001'; DROP TABLE extensions;--",
]);
