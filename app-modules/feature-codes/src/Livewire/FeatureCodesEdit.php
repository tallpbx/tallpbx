<?php

declare(strict_types=1);

namespace Modules\FeatureCodes\Livewire;

use App\Jobs\ReloadFreeSwitchXml;
use App\Support\BaseEditComponent;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\FeatureCodes\Services\FeatureCodeServiceInterface;

/**
 * Livewire component for creating and editing feature codes.
 */
class FeatureCodesEdit extends BaseEditComponent
{
    public string $name = '';

    public string $code = '';

    public string $description = '';

    public ?string $codeId = null;

    private FeatureCodeServiceInterface $featureCodeService;

    /**
     * Boot the component with the feature code service.
     */
    public function boot(FeatureCodeServiceInterface $featureCodeService): void
    {
        $this->featureCodeService = $featureCodeService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $codeId = null): void
    {
        $this->loadTenants();

        if ($codeId !== null) {
            $this->codeId = $codeId;
            $featureCode = FeatureCode::withoutGlobalScope('tenant')->findOrFail($codeId);
            // Tenant users may only open records of their active tenant.
            $this->assertCanAccessTenantRecord($featureCode);
            $this->tenantId = $featureCode->tenant_id;
            $this->name = $featureCode->name;
            $this->code = $featureCode->code;
            $this->description = $featureCode->description ?? '';
            $this->enabled = $featureCode->enabled;
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->codeId !== null;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->codeId !== null) {
            $code = FeatureCode::withoutGlobalScope('tenant')->findOrFail($this->codeId);
            $this->featureCodeService->update($code, $data);
        } else {
            $this->featureCodeService->create($data);
        }

        $this->redirect(route('panel.feature-codes.index'));

        // Queue a reloadxml so FreeSWITCH picks up the feature code change
        ReloadFreeSwitchXml::dispatch('feature code saved');
    }

    protected function rules(): array
    {
        return [
            'tenantId' => ['required', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
