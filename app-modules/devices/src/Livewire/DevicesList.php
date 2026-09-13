<?php

declare(strict_types=1);

namespace Modules\Devices\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Modules\Devices\Models\Device;
use Modules\Devices\Services\DeviceServiceInterface;

/**
 * Livewire component that lists devices with CRUD actions.
 */
class DevicesList extends BaseListComponent
{
    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private DeviceServiceInterface $deviceService;

    /**
     * Boot the component with the device service.
     */
    public function boot(DeviceServiceInterface $deviceService): void
    {
        $this->deviceService = $deviceService;
    }

    /**
     * Delete a device by its ID.
     */
    public function deleteDevice(string $deviceId): void
    {
        $device = Device::withoutGlobalScope('tenant')->findOrFail($deviceId);
        $this->deviceService->delete($device);
        $this->cancelDeviceDeletion();
        $this->showSuccess('Device deleted.');
        $this->dispatch('device-deleted');
    }

    /** Open the shared destructive-action confirmation for one device. */
    public function confirmDeviceDeletion(string $deviceId): void
    {
        $device = Device::withoutGlobalScope('tenant')->findOrFail($deviceId);
        $this->pendingDeletionId = $device->id;
        $this->pendingDeletionName = $device->mac_address;
    }

    /** Close the device deletion confirmation without changing the device. */
    public function cancelDeviceDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }

    /** Render the paginated device list. */
    public function render(): View
    {
        return view('devices::devices-list', [
            'devices' => Device::withoutGlobalScope('tenant')
                ->orderBy('vendor')
                ->paginate(15),
        ]);
    }
}
