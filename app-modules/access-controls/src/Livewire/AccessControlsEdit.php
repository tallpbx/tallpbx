<?php

declare(strict_types=1);

namespace Modules\AccessControls\Livewire;

use App\Support\BaseEditComponent;
use Illuminate\Validation\Rule;
use Modules\AccessControls\Models\AccessControl;
use Modules\AccessControls\Services\AccessControlServiceInterface;

/**
 * Livewire component for creating and editing access control rules.
 */
class AccessControlsEdit extends BaseEditComponent
{
    public string $name = '';

    public string $description = '';

    public string $action = 'allow';

    public ?string $ruleId = null;

    private AccessControlServiceInterface $accessControlService;

    /**
     * Boot the component with the access control service.
     */
    public function boot(AccessControlServiceInterface $accessControlService): void
    {
        $this->accessControlService = $accessControlService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $ruleId = null): void
    {
        $this->loadTenants();

        if ($ruleId !== null) {
            $this->ruleId = $ruleId;
            $rule = AccessControl::withoutGlobalScope('tenant')->findOrFail($ruleId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($rule);
            $this->tenantId = $rule->tenant_id;
            $this->name = $rule->name;
            $this->description = $rule->description ?? '';
            $this->action = $rule->action;
            $this->enabled = $rule->enabled;
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->ruleId !== null;
    }

    /**
     * Save the access control rule – creates or updates.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'action' => $this->action,
            'enabled' => $this->enabled,
        ];

        if ($this->ruleId !== null) {
            $rule = AccessControl::withoutGlobalScope('tenant')->findOrFail($this->ruleId);
            $this->accessControlService->update($rule, $data);
        } else {
            $this->accessControlService->create($data);
        }

        $this->redirect(route('panel.access-controls.index'));
    }

    /**
     * Validation rules.
     */
    protected function rules(): array
    {
        return [
            'tenantId' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'action' => ['required', Rule::in(['allow', 'deny'])],
        ];
    }

    /**
     * Render the form component.
     */
}
