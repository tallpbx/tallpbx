<?php

declare(strict_types=1);

namespace Modules\Emergency\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Emergency\Services\EmergencyServiceInterface;

/**
 * Create/edit form for emergency (E911) configurations.
 *
 * Provides fields for caller ID, physical address, and
 * optional GPS coordinates. Loads existing record data
 * when editing.
 */
class EmergencyEdit extends BaseEditComponent
{
    /** @var Collection<int, Tenant> Available tenants */
    public Collection $tenants;

    public string $callerId = '';

    public string $address = '';

    public string $latitude = '';

    public string $longitude = '';

    public ?string $emergencyId = null;

    /** The emergency service instance. */
    private EmergencyServiceInterface $service;

    /**
     * Inject the emergency service via Livewire's dependency injection.
     */
    public function boot(EmergencyServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Initialize form state, loading existing data when editing.
     */
    public function mount(?string $emergencyId = null): void
    {
        $this->loadTenants();

        if ($emergencyId === null) {
            return;
        }

        $record = $this->service->find($emergencyId);
        $this->emergencyId = $record->id;
        $this->tenantId = $record->tenant_id;
        $this->callerId = $record->caller_id ?? '';
        $this->address = $record->address;
        $this->latitude = $record->latitude ?? '';
        $this->longitude = $record->longitude ?? '';
    }

    /**
     * Whether we are editing an existing record or creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->emergencyId !== null;
    }

    /**
     * Validate and persist the emergency configuration.
     */
    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'caller_id' => $this->callerId ?: null,
            'address' => $this->address,
            'latitude' => $this->latitude ?: null,
            'longitude' => $this->longitude ?: null,
        ];

        if ($this->isEdit) {
            $record = $this->service->find($this->emergencyId);
            $this->service->update($record, $data);
        } else {
            $this->service->create($data);
        }

        $this->redirect(route('panel.emergency.index'), navigate: true);

        // Queue a reloadxml so FreeSWITCH picks up emergency config changes
        ReloadFreeSwitchXml::dispatch('emergency config saved');
    }

    /**
     * Validation rules for the emergency form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => 'required|exists:tenants,id',
            'callerId' => 'nullable|string|max:255',
            'address' => 'required|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ];
    }

    /**
     * Render the emergency config form view.
     */
}
