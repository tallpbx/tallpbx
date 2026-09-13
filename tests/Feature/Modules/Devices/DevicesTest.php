<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Devices\Livewire\DevicesEdit;
use Modules\Devices\Livewire\DevicesList;
use Modules\Devices\Models\Device;
use Modules\Devices\Services\DeviceService;
use Modules\SipAccounts\Models\SipAccount;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('normalizes MAC addresses on create', function () {
    $device = app(DeviceService::class)->create([
        'tenant_id' => $this->tenant->id,
        'vendor' => 'yealink',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'enabled' => true,
    ]);

    expect($device->mac_address)->toBe('aa:bb:cc:dd:ee:ff');
});

it('rejects a duplicate MAC address globally', function () {
    Device::factory()->create(['tenant_id' => $this->tenant->id, 'mac_address' => 'aa:bb:cc:dd:ee:ff']);

    app(DeviceService::class)->create([
        'tenant_id' => $this->tenant->id,
        'vendor' => 'yealink',
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'enabled' => true,
    ]);
})->throws(ValidationException::class);

it('creates a device with a linked sip account', function () {
    $sipAccount = SipAccount::factory()->create(['tenant_id' => $this->tenant->id]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('vendor', 'yealink')
        ->set('macAddress', 'AA:BB:CC:DD:EE:01')
        ->set('sipAccountId', $sipAccount->id)
        ->call('save')
        ->assertRedirect(route('panel.devices.index'));

    $this->assertDatabaseHas('devices', [
        'mac_address' => 'aa:bb:cc:dd:ee:01',
        'sip_account_id' => $sipAccount->id,
    ]);
});

it('prefills the sip account when editing a device', function () {
    $sipAccount = SipAccount::factory()->create(['tenant_id' => $this->tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $this->tenant->id,
        'sip_account_id' => $sipAccount->id,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class, ['deviceId' => $device->id])
        ->assertSet('sipAccountId', $sipAccount->id)
        ->assertSet('macAddress', $device->mac_address);
});

it('rejects a sip account from another tenant', function () {
    $otherTenant = Tenant::factory()->create();
    $sipAccount = SipAccount::factory()->create(['tenant_id' => $otherTenant->id]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('vendor', 'yealink')
        ->set('macAddress', 'AA:BB:CC:DD:EE:02')
        ->set('sipAccountId', $sipAccount->id)
        ->call('save')
        ->assertHasErrors(['sipAccountId']);
});

it('validates the mac address format', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('vendor', 'yealink')
        ->set('macAddress', 'not-a-mac')
        ->call('save')
        ->assertHasErrors(['macAddress' => 'regex']);
});

it('renders the device list and deletes a device', function () {
    $device = Device::factory()->create(['tenant_id' => $this->tenant->id]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DevicesList::class)
        ->assertOk()
        ->call('deleteDevice', $device->id)
        ->assertOk();

    $this->assertModelMissing($device);
});
