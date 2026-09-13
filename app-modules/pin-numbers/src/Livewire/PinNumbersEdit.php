<?php

declare(strict_types=1);

namespace Modules\PinNumbers\Livewire;

use App\Support\BaseEditComponent;
use Modules\PinNumbers\Models\PinNumber;
use Modules\PinNumbers\Services\PinNumberService;

/**
 * Livewire component for creating and editing PIN numbers.
 */
class PinNumbersEdit extends BaseEditComponent
{
    public string $pinNumber = '';

    public string $description = '';

    public ?string $pinId = null;

    private PinNumberService $pinNumberService;

    public function boot(PinNumberService $pinNumberService): void
    {
        $this->pinNumberService = $pinNumberService;
    }

    public function mount(?string $pinId = null): void
    {
        $this->loadTenants();

        if ($pinId !== null) {
            $this->pinId = $pinId;
            $pin = PinNumber::withoutGlobalScope('tenant')->findOrFail($pinId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($pin);
            $this->tenantId = $pin->tenant_id;
            $this->pinNumber = $pin->pin_number;
            $this->description = $pin->description ?? '';
            $this->enabled = $pin->enabled;
        }
    }

    public function getIsEditProperty(): bool
    {
        return $this->pinId !== null;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'pin_number' => $this->pinNumber,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->pinId !== null) {
            $pin = PinNumber::withoutGlobalScope('tenant')->findOrFail($this->pinId);
            $this->pinNumberService->update($pin, $data);
        } else {
            $this->pinNumberService->create($data);
        }

        $this->redirect(route('panel.pin-numbers.index'));
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'pinNumber' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
