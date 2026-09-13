<?php

declare(strict_types=1);

namespace Modules\Devices\Livewire;

use App\Support\BaseEditComponent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Modules\Devices\Models\Device;
use Modules\Devices\Services\DeviceServiceInterface;
use Modules\SipAccounts\Models\SipAccount;

/**
 * Livewire component for creating and editing devices.
 */
class DevicesEdit extends BaseEditComponent
{
    public string $vendor = '';

    public string $model = '';

    public string $macAddress = '';

    public string $template = '';

    /** The linked SIP account id (null = no line linked). */
    public ?string $sipAccountId = null;

    /** @var Collection<int, SipAccount> */
    public Collection $sipAccounts;

    public ?string $deviceId = null;

    private DeviceServiceInterface $deviceService;

    /**
     * Boot the component with the device service.
     */
    public function boot(DeviceServiceInterface $deviceService): void
    {
        $this->deviceService = $deviceService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $deviceId = null): void
    {
        $this->loadTenants();

        if ($deviceId !== null) {
            $this->deviceId = $deviceId;
            $device = Device::withoutGlobalScope('tenant')->findOrFail($deviceId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($device);
            $this->tenantId = $device->tenant_id;
            $this->vendor = $device->vendor;
            $this->model = $device->model ?? '';
            $this->macAddress = $device->mac_address;
            $this->template = $device->template ?? '';
            $this->sipAccountId = $device->sip_account_id;
            $this->enabled = $device->enabled;
        }

        $this->loadSipAccounts();
    }

    /**
     * Load the enabled SIP accounts of the currently selected tenant.
     */
    private function loadSipAccounts(): void
    {
        // Resolve the tenant through the guard-aware helper so tenant users
        // always see the accounts of their active tenant.
        $this->sipAccounts = SipAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->resolveTenantId())
            ->where('enabled', true)
            ->orderBy('auth_username')
            ->get();
    }

    /**
     * Reset the line selection when the admin switches tenant.
     */
    public function updatedTenantId(): void
    {
        $this->sipAccountId = null;
        $this->loadSipAccounts();
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->deviceId !== null;
    }

    /**
     * Save the device – creates or updates.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        // Admins choose the tenant from the dropdown; tenant users are
        // always pinned to their active tenant context.
        $data = [
            'tenant_id' => $this->resolveTenantId(),
            'vendor' => $this->vendor,
            'model' => $this->model ?: null,
            'mac_address' => $this->macAddress,
            'template' => $this->template ?: null,
            'sip_account_id' => $this->sipAccountId ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->deviceId !== null) {
            $device = Device::withoutGlobalScope('tenant')->findOrFail($this->deviceId);
            // Re-check ownership at save time in case the form state was
            // tampered with between mount and submit.
            $this->assertCanAccessTenantRecord($device);
            $this->deviceService->update($device, $data);
        } else {
            $this->deviceService->create($data);
        }

        $this->redirect(route('panel.devices.index'));
    }

    protected function rules(): array
    {
        $rules = [
            'vendor' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'macAddress' => ['required', 'string', 'max:255', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$|^([0-9A-Fa-f]{4}[.]){2}([0-9A-Fa-f]{4})$/'],
            'template' => ['nullable', 'string', 'max:255'],
            'sipAccountId' => [
                'nullable',
                // The account must belong to the resolved tenant (tenant
                // boundary enforced at save time, not just in the selector).
                Rule::exists('sip_accounts', 'id')->where('tenant_id', $this->resolveTenantId()),
            ],
        ];

        // Only admin users pick a tenant from the dropdown; tenant users are
        // auto-scoped to their active tenant context.
        if ($this->isAdminGuard()) {
            $rules['tenantId'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }
}
