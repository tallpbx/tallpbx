<?php

declare(strict_types=1);

namespace Modules\TenantLimits\Livewire;

use App\Support\BaseEditComponent;
use Modules\TenantLimits\Models\TenantLimit;
use Modules\TenantLimits\Services\TenantLimitService;

class TenantLimitsEdit extends BaseEditComponent
{
    public string $resource = '';

    public int $softLimit = 0;

    public int $hardLimit = 0;

    public ?string $limitId = null;

    private TenantLimitService $limitService;

    public function boot(TenantLimitService $limitService): void
    {
        $this->limitService = $limitService;
    }

    public function mount(?string $limitId = null): void
    {
        $this->loadTenants();

        if ($limitId === null) {
            return;
        }

        $limit = TenantLimit::withoutGlobalScope('tenant')->findOrFail($limitId);
        // Tenant users may only open records of their active tenant.
        $this->assertCanAccessTenantRecord($limit);
        $this->limitId = $limit->id;
        $this->tenantId = $limit->tenant_id;
        $this->resource = $limit->resource;
        $this->softLimit = $limit->soft_limit;
        $this->hardLimit = $limit->hard_limit;
    }

    public function getIsEditProperty(): bool
    {
        return $this->limitId !== null;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'resource' => $this->resource,
            'soft_limit' => $this->softLimit,
            'hard_limit' => $this->hardLimit,
        ];

        if ($this->isEdit) {
            $limit = TenantLimit::withoutGlobalScope('tenant')->findOrFail($this->limitId);
            $this->limitService->update($limit, $data);
        } else {
            $this->limitService->create($data);
        }

        $this->redirect(route('panel.tenant-limits.index'), navigate: true);
    }

    public function rules(): array
    {
        return [
            'tenantId' => 'required|exists:tenants,id',
            'resource' => 'required|string|max:255',
            'softLimit' => 'required|integer|min:0',
            'hardLimit' => 'required|integer|min:0|gte:softLimit',
        ];
    }
}
