<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\Devices\Livewire\DevicesList;
use Modules\Devices\Models\Device;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the devices list component', function () {
    Device::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesList::class)
        ->assertOk()
        ->assertSee('Devices')
        ->assertSee('A physical piece of hardware or softphone client')
        ->assertViewHas('devices', function ($devices) {
            return $devices->count() === 2;
        });
});

it('displays vendor, model, and mac address', function () {
    Device::factory()->create([
        'vendor' => 'Polycom',
        'model' => 'VVX 450',
        'mac_address' => '00:11:22:33:44:55',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesList::class)
        ->assertSee('Polycom')
        ->assertSee('VVX 450')
        ->assertSee('00:11:22:33:44:55');
});

it('deletes a device', function () {
    $device = Device::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesList::class)
        ->call('deleteDevice', $device->id)
        ->assertDispatched('device-deleted');

    $this->assertModelMissing($device);
});

it('opens the shared confirmation modal before deleting a device', function (): void {
    $device = Device::factory()->create(['mac_address' => '00:11:22:33:44:55']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesList::class)
        ->call('confirmDeviceDeletion', $device->id)
        ->assertSet('pendingDeletionId', $device->id)
        ->assertSet('pendingDeletionName', '00:11:22:33:44:55')
        ->assertSee('Delete Device?');
});

it('shows empty state when no devices exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesList::class)
        ->assertSee('No devices found');
});
