<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\FreeSwitchServiceInterface;
use Livewire\Livewire;
use Modules\ClickToCall\Livewire\ClickToCallForm;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
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
