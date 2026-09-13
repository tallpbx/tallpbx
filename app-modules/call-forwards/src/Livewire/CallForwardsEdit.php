<?php

declare(strict_types=1);

namespace Modules\CallForwards\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\CallForwards\Models\CallForward;
use Modules\CallForwards\Services\CallForwardServiceInterface;

/**
 * Livewire component for creating and editing call forward rules.
 * Handles form validation, data persistence, and redirects
 * back to the call forward list on success.
 */
class CallForwardsEdit extends BaseEditComponent
{
    public ?string $extensionUuid = null;

    public string $forwardType = 'unconditional';

    public string $destination = '';

    public int $ringTimeout = 30;

    public ?string $forwardId = null;

    private CallForwardServiceInterface $callForwardService;

    /**
     * Inject the call forward service via dependency injection.
     */
    public function boot(CallForwardServiceInterface $callForwardService): void
    {
        $this->callForwardService = $callForwardService;
    }

    /**
     * Initialize the component. If a forwardId is provided,
     * loads existing data for editing.
     */
    public function mount(?string $forwardId = null): void
    {
        $this->loadTenants();

        if ($forwardId !== null) {
            $this->forwardId = $forwardId;
            $forward = CallForward::withoutGlobalScope('tenant')->findOrFail($forwardId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($forward);
            $this->tenantId = $forward->tenant_id;
            $this->extensionUuid = $forward->extension_uuid;
            $this->forwardType = $forward->forward_type;
            $this->destination = $forward->destination;
            $this->ringTimeout = $forward->ring_timeout;
            $this->enabled = $forward->enabled;
        }
    }

    /**
     * Determine if we are in edit mode (vs. create mode).
     */
    public function getIsEditProperty(): bool
    {
        return $this->forwardId !== null;
    }

    /**
     * Validate and save the call forward rule.
     * Creates a new record or updates an existing one,
     * then redirects to the list page.
     */
    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'extension_uuid' => $this->extensionUuid,
            'forward_type' => $this->forwardType,
            'destination' => $this->destination,
            'ring_timeout' => $this->ringTimeout,
            'enabled' => $this->enabled,
        ];

        if ($this->forwardId !== null) {
            $forward = CallForward::withoutGlobalScope('tenant')->findOrFail($this->forwardId);
            $this->callForwardService->update($forward, $data);
        } else {
            $this->callForwardService->create($data);
        }

        $this->redirect(route('panel.call-forwards.index'));

        // Queue a reloadxml so FreeSWITCH picks up the call forward change
        ReloadFreeSwitchXml::dispatch('call forward saved');
    }

    /**
     * Validation rules for the call forward form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'extensionUuid' => ['required', 'string', 'exists:extensions,id'],
            'forwardType' => ['required', 'string', 'in:unconditional,busy,noanswer,notfound'],
            'destination' => ['required', 'string', 'max:255'],
            'ringTimeout' => ['required', 'integer', 'min:1', 'max:300'],
        ];
    }
}
