<?php

declare(strict_types=1);

namespace Modules\Devices\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Devices\Models\Device;

/**
 * Service implementation for managing devices with MAC address
 * normalization and global uniqueness enforcement.
 */
class DeviceService implements DeviceServiceInterface
{
    /**
     * Create a new device with a normalized MAC address.
     */
    public function create(array $data): Device
    {
        $data['mac_address'] = $this->normalizeMac($data['mac_address']);
        $this->validateUniqueMac($data['mac_address']);

        return Device::create($data);
    }

    /**
     * Update an existing device.
     */
    public function update(Device $device, array $data): Device
    {
        if (isset($data['mac_address'])) {
            $data['mac_address'] = $this->normalizeMac($data['mac_address']);
            if ($data['mac_address'] !== $device->mac_address) {
                $this->validateUniqueMac($data['mac_address'], $device->id);
            }
        }

        $device->update($data);

        return $device->fresh();
    }

    /**
     * Delete a device.
     */
    public function delete(Device $device): void
    {
        $device->delete();
    }

    /**
     * Get all devices for a tenant.
     */
    public function getByTenant(int $tenantId): Collection
    {
        return Device::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get();
    }

    /**
     * Normalize a MAC address to lowercase colon-separated hex pairs.
     */
    private function normalizeMac(string $mac): string
    {
        $mac = preg_replace('/[^a-fA-F0-9]/', '', $mac);
        $mac = strtolower($mac);

        return implode(':', str_split($mac, 2));
    }

    /**
     * Ensure the MAC address is globally unique.
     *
     * @throws ValidationException
     */
    private function validateUniqueMac(string $mac, ?string $excludeId = null): void
    {
        $query = Device::withoutGlobalScope('tenant')->where('mac_address', $mac);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'mac_address' => ['This MAC address is already registered.'],
            ]);
        }
    }
}
