<?php

declare(strict_types=1);

namespace Modules\Provision\Livewire;

use App\Support\BaseEditComponent;
use Modules\Provision\Models\ProvisionTemplate;
use Modules\Provision\Services\ProvisionServiceInterface;

#[Layout('layouts.app')]
class TemplateEdit extends BaseEditComponent
{
    public string $name = '';

    public string $vendor = '';

    public string $model = '';

    public string $filePath = '';

    public ?string $templateId = null;

    private ProvisionServiceInterface $service;

    public function boot(ProvisionServiceInterface $service): void
    {
        $this->service = $service;
    }

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

    public function getIsEditProperty(): bool
    {
        return $this->templateId !== null;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'vendor' => $this->vendor,
            'model' => $this->model ?: null,
            'file_path' => $this->filePath,
            'enabled' => $this->enabled,
        ];

        if ($this->templateId !== null) {
            $t = ProvisionTemplate::withoutGlobalScope('tenant')->findOrFail($this->templateId);
            $this->service->updateTemplate($t, $data);
        } else {
            $this->service->createTemplate($data);
        }

        $this->redirect(route('panel.provision.templates.index'));
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'vendor' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'filePath' => ['required', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ];
    }
}
