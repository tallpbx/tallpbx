<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\Devices\Models\Device;
use Modules\Devices\Services\DeviceServiceInterface;

beforeEach(function () {
    $this->service = app(DeviceServiceInterface::class);
});

it('creates a device', function () {
    $tenant = Tenant::factory()->create();

    $device = $this->service->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'Polycom',
        'model' => 'VVX 450',
        'mac_address' => '00:11:22:33:44:55',
        'template' => 'polycom_vvx',
        'enabled' => true,
    ]);

    expect($device)
        ->toBeInstanceOf(Device::class)
        ->vendor->toBe('Polycom')
        ->model->toBe('VVX 450')
        ->mac_address->toBe('00:11:22:33:44:55')
        ->template->toBe('polycom_vvx');
});

it('updates a device', function () {
    $device = Device::factory()->create(['vendor' => 'Yealink']);

    $updated = $this->service->update($device, [
        'vendor' => 'Polycom',
        'model' => 'VVX 450',
    ]);

    expect($updated->vendor)->toBe('Polycom')
        ->and($updated->model)->toBe('VVX 450');
});

it('deletes a device', function () {
    $device = Device::factory()->create();

    $this->service->delete($device);

    $this->assertModelMissing($device);
});

it('enforces unique mac address', function () {
    $tenant = Tenant::factory()->create();

    $this->service->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'Polycom',
        'mac_address' => '00:11:22:33:44:55',
    ]);

    $this->expectException(ValidationException::class);

    $this->service->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'Cisco',
        'mac_address' => '00:11:22:33:44:55',
    ]);
});

it('normalizes mac address on save', function () {
    $tenant = Tenant::factory()->create();

    $device = $this->service->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'Polycom',
        'mac_address' => '00-11-22-33-44-55',
    ]);

    expect($device->mac_address)->toBe('00:11:22:33:44:55');
});

it('returns devices scoped by tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    Device::factory()->forTenant($tenant1->id)->count(2)->create();
    Device::factory()->forTenant($tenant2->id)->count(1)->create();

    expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
});
