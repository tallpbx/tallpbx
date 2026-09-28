<?php

declare(strict_types=1);

namespace Modules\CallFlows\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\CallFlows\Models\CallFlow;
use Modules\CallFlows\Services\CallFlowService;

/**
 * Livewire component for creating and editing call flow rules.
 */
class CallFlowsEdit extends BaseEditComponent
{
    public string $name = '';

    public string $extension = '';

    public string $destinationType = '';

    public string $destinationId = '';

    public ?string $callFlowId = null;

    private CallFlowService $callFlowService;

    /**
     * Inject the call flow service used by this component.
     */
    public function boot(CallFlowService $callFlowService): void
    {
        $this->callFlowService = $callFlowService;
    }

    /**
     * Open the create form, or load the given flow for editing when a
     * record id is supplied (tenant users may only open their own).
     */
    public function mount(?string $callFlowId = null): void
    {
        $this->loadTenants();

        if ($callFlowId !== null) {
            $this->callFlowId = $callFlowId;
            $flow = CallFlow::withoutGlobalScope('tenant')->findOrFail($callFlowId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($flow);
            $this->tenantId = $flow->tenant_id;
            $this->name = $flow->name;
            $this->extension = $flow->extension;
            $this->destinationType = $flow->destination_type ?? '';
            $this->destinationId = $flow->destination_id ?? '';
            $this->enabled = $flow->enabled;
        }
    }

    /**
     * Whether the form is editing an existing flow rather than
     * creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->callFlowId !== null;
    }

    /**
     * Validate the form, store the flow, and ask FreeSWITCH to reload
     * its configuration so the change takes effect.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'extension' => $this->extension,
            'destination_type' => $this->destinationType ?: null,
            'destination_id' => $this->destinationId ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->callFlowId !== null) {
            $flow = CallFlow::withoutGlobalScope('tenant')->findOrFail($this->callFlowId);
            $this->callFlowService->update($flow, $data);
        } else {
            $this->callFlowService->create($data);
        }

        $this->redirect(route('panel.call-flows.index'));

        // Queue a reloadxml so FreeSWITCH picks up the call flow change
        ReloadFreeSwitchXml::dispatch('call flow saved');
    }

    /**
     * Validation rules for the call flow form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'extension' => ['required', 'string', 'max:255'],
            'destinationType' => ['nullable', 'string', 'max:255'],
            'destinationId' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ];
    }
}
