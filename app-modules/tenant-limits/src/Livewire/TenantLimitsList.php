<?php

declare(strict_types=1);

namespace Modules\TenantLimits\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\TenantLimits\Models\TenantLimit;
use Modules\TenantLimits\Services\TenantLimitService;

class TenantLimitsList extends BaseListComponent
{
    /** @var Collection<int, TenantLimit> */
    public Collection $limits;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private TenantLimitService $limitService;

    public function boot(TenantLimitService $limitService): void
    {
        $this->limitService = $limitService;
    }

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->limits = TenantLimit::withoutGlobalScope('tenant')
            ->orderBy('resource')
            ->get();
    }

    public function deleteLimit(string $id): void
    {
        $limit = TenantLimit::withoutGlobalScope('tenant')->findOrFail($id);
        $this->limitService->delete($limit);
        $this->cancelLimitDeletion();
        $this->load();
        $this->showSuccess('Tenant limit deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }

    /** Open the shared deletion confirmation for a tenant limit. */
    public function confirmLimitDeletion(string $id): void
    {
        $limit = TenantLimit::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $limit->id;
        $this->pendingDeletionName = $limit->resource;
    }

    /** Close the tenant-limit deletion confirmation. */
    public function cancelLimitDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
