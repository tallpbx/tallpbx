<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Devices\Livewire\DevicesEdit;
use Modules\Devices\Models\Device;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class)
        ->assertOk()
        ->assertSee('Create Device')
        ->assertSee('A physical piece of hardware or softphone client')
        ->assertSet('vendor', '')
        ->assertSet('model', '')
        ->assertSet('macAddress', '');
});

it('renders the edit form with existing device data', function () {
    $device = Device::factory()->create([
        'vendor' => 'Polycom',
        'model' => 'VVX 450',
        'mac_address' => '00:11:22:33:44:55',
        'template' => 'polycom_vvx',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class, ['deviceId' => $device->id])
        ->assertOk()
        ->assertSee('Edit Device')
        ->assertSee('A physical piece of hardware or softphone client')
        ->assertSet('vendor', 'Polycom')
        ->assertSet('model', 'VVX 450')
        ->assertSet('macAddress', '00:11:22:33:44:55');
});

it('creates a new device', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('vendor', 'Yealink')
        ->set('model', 'T46S')
        ->set('macAddress', 'AA:BB:CC:DD:EE:FF')
        ->set('template', 'yealink_t4')
        ->call('save')
        ->assertRedirect(route('panel.devices.index'));

    $this->assertDatabaseHas('devices', [
        'vendor' => 'Yealink',
        'model' => 'T46S',
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
    ]);
});

it('updates an existing device', function () {
    $device = Device::factory()->create(['vendor' => 'Old Vendor']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class, ['deviceId' => $device->id])
        ->set('vendor', 'New Vendor')
        ->set('model', 'New Model')
        ->call('save')
        ->assertRedirect(route('panel.devices.index'));

    $this->assertDatabaseHas('devices', [
        'id' => $device->id,
        'vendor' => 'New Vendor',
        'model' => 'New Model',
    ]);
});

it('validates mac address is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class)
        ->set('vendor', 'Polycom')
        ->set('macAddress', '')
        ->call('save')
        ->assertHasErrors(['macAddress' => 'required']);
});

it('validates mac address format', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class)
        ->set('vendor', 'Polycom')
        ->set('macAddress', 'not-a-mac')
        ->set('tenantId', $this->tenant->id)
        ->call('save')
        ->assertHasErrors(['macAddress']);
});

it('validates vendor is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class)
        ->set('vendor', '')
        ->call('save')
        ->assertHasErrors(['vendor' => 'required']);
});
