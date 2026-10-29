<?php

declare(strict_types=1);

namespace Modules\Provision\Livewire;

use App\Support\BaseEditComponent;
use App\Support\PanelActor;
use Livewire\Attributes\Locked;
use Modules\Provision\Models\ProvisionTemplate;
use Modules\Provision\Services\ProvisionService;
use Modules\Provision\Services\ProvisionServiceInterface;

/**
 * Livewire component for creating and editing provisioning templates.
 */
#[Layout('layouts.app')]
class TemplateEdit extends BaseEditComponent
{
    public string $name = '';

    public string $vendor = '';

    public string $model = '';

    public string $filePath = '';

    #[Locked]
    public ?string $templateId = null;

    private ProvisionServiceInterface $service;

    /**
     * Inject the provision service used by this component.
     */
    public function boot(ProvisionServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Open the create form, or load the given template for editing when
     * a record id is supplied (tenant users may only open their own).
     */
    public function mount(?string $templateId = null): void
    {
        $this->loadTenants();

        if ($templateId !== null) {
            $this->templateId = $templateId;
            $t = ProvisionTemplate::withoutGlobalScope('tenant')->findOrFail($templateId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($t);
            $this->tenantId = $t->tenant_id;
            $this->name = $t->name;
            $this->vendor = $t->vendor;
            $this->model = $t->model ?? '';
            $this->filePath = $t->file_path;
            $this->enabled = $t->enabled;
        }
    }

    /**
     * Whether the form is editing an existing template rather than
     * creating a new one.
     */
    public function getIsEditProperty(): bool
    {
        return $this->templateId !== null;
    }

    /**
     * Validate the form and create or update the provisioning template.
     */
    public function save(): void
    {
        if ($this->isAdminGuard()) {

            abort_unless(PanelActor::can($this->templateId === null ? 'provision.create' : 'provision.edit'), 403);

        }
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->resolveTenantId(),
            'name' => $this->name,
            'vendor' => $this->vendor,
            'model' => $this->model ?: null,
            'file_path' => $this->filePath,
            'enabled' => $this->enabled,
        ];

        if ($this->templateId !== null) {
            $t = ProvisionTemplate::withoutGlobalScope('tenant')->findOrFail($this->templateId);
            $this->assertCanAccessTenantRecord($t);
            $this->service->updateTemplate($t, $data);
        } else {
            $this->service->createTemplate($data);
        }

        $this->redirect(route('panel.provision.templates.index'));
    }

    /**
     * Validation rules for the provisioning template form.
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'vendor' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'filePath' => ['required', 'string', 'max:255', 'regex:'.ProvisionService::TEMPLATE_PATH_PATTERN],
            'enabled' => ['boolean'],
        ];

        if ($this->isAdminGuard()) {
            $rules['tenantId'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }
}
