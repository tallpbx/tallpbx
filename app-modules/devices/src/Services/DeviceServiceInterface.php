<?php

declare(strict_types=1);

namespace Modules\Devices\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Devices\Models\Device;

/**
 * Service for managing physical and soft phone devices.
 */
interface DeviceServiceInterface
{
    /**
     * Create a new device with MAC address normalization.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Device;

    /**
     * Update an existing device.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Device $device, array $data): Device;

    /**
     * Delete a device.
     */
    public function delete(Device $device): void;

    /**
     * Get all devices for a specific tenant.
     *
     * @return Collection<int, Device>
     */
    public function getByTenant(int $tenantId): Collection;
}
