<?php

declare(strict_types=1);

namespace Modules\Destinations\Livewire;

use App\Support\BaseEditComponent;
use Modules\Destinations\Models\Destination;
use Modules\Destinations\Services\DestinationServiceInterface;

/**
 * Livewire component for creating and editing destinations.
 */
class DestinationsEdit extends BaseEditComponent
{
    public string $name = '';

    public string $type = '';

    public string $dialString = '';

    public string $description = '';

    public ?string $destinationId = null;

    private DestinationServiceInterface $destinationService;

    /**
     * Boot the component with the destination service.
     */
    public function boot(DestinationServiceInterface $destinationService): void
    {
        $this->destinationService = $destinationService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $destinationId = null): void
    {
        $this->loadTenants();

        if ($destinationId !== null) {
            $this->destinationId = $destinationId;
            $destination = Destination::withoutGlobalScope('tenant')->findOrFail($destinationId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($destination);
            $this->tenantId = $destination->tenant_id;
            $this->name = $destination->name;
            $this->type = $destination->type;
            $this->dialString = $destination->dial_string;
            $this->description = $destination->description ?? '';
            $this->enabled = $destination->enabled;
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->destinationId !== null;
    }

    /**
     * Save the destination – creates or updates.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'type' => $this->type,
            'dial_string' => $this->dialString,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->destinationId !== null) {
            $destination = Destination::withoutGlobalScope('tenant')->findOrFail($this->destinationId);
            $this->destinationService->update($destination, $data);
        } else {
            $this->destinationService->create($data);
        }

        $this->redirect(route('panel.destinations.index'));
    }

    protected function rules(): array
    {
        return [
            'tenantId' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:100'],
            'dialString' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
