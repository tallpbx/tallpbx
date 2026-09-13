<?php

declare(strict_types=1);

namespace Modules\Dialplans\Livewire;

use App\Support\BaseEditComponent;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Services\DialplanServiceInterface;

/**
 * Livewire component for creating and editing dialplans.
 */
class DialplansEdit extends BaseEditComponent
{
    public string $name = '';

    public string $context = '';

    public string $description = '';

    public string $order = '100';

    public ?string $dialplanId = null;

    private DialplanServiceInterface $dialplanService;

    /**
     * Boot the component with the dialplan service.
     */
    public function boot(DialplanServiceInterface $dialplanService): void
    {
        $this->dialplanService = $dialplanService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $dialplanId = null): void
    {
        $this->loadTenants();

        if ($dialplanId !== null) {
            $this->dialplanId = $dialplanId;
            $dialplan = Dialplan::withoutGlobalScope('tenant')->findOrFail($dialplanId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($dialplan);
            $this->tenantId = $dialplan->tenant_id;
            $this->name = $dialplan->name;
            $this->context = $dialplan->context;
            $this->description = $dialplan->description ?? '';
            $this->order = (string) $dialplan->order;
            $this->enabled = $dialplan->enabled;
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->dialplanId !== null;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'context' => $this->context,
            'description' => $this->description ?: null,
            'order' => (int) $this->order,
            'enabled' => $this->enabled,
        ];

        if ($this->dialplanId !== null) {
            $dialplan = Dialplan::withoutGlobalScope('tenant')->findOrFail($this->dialplanId);
            $this->dialplanService->update($dialplan, $data);
        } else {
            $this->dialplanService->create($data);
        }

        $this->redirect(route('panel.dialplans.index'));
    }

    protected function rules(): array
    {
        return [
            'tenantId' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'context' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'order' => ['required', 'numeric', 'min:1'],
        ];
    }
}
