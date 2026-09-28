<?php

declare(strict_types=1);

namespace Modules\TenantLimits\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\TenantLimits\Models\TenantLimit;
use Modules\TenantLimits\Services\TenantLimitService;

/**
 * Livewire component listing tenant resource limits with a delete action.
 */
class TenantLimitsList extends BaseListComponent
{
    /** @var Collection<int, TenantLimit> */
    public Collection $limits;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private TenantLimitService $limitService;

    /**
     * Inject the tenant limit service used by this component.
     */
    public function boot(TenantLimitService $limitService): void
    {
        $this->limitService = $limitService;
    }

    /**
     * Load the tenant limits list when the page opens.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch limits ordered by resource. Administrators see every
     * tenant's limits; tenant users only see their own.
     */
    private function load(): void
    {
        $query = $this->isAdminGuard()
            ? TenantLimit::withoutGlobalScope('tenant')
            : TenantLimit::query();

        $this->limits = $query->orderBy('resource')->get();
    }

    /**
     * Delete the confirmed limit and refresh the list.
     */
    public function deleteLimit(string $id): void
    {
        $limit = TenantLimit::withoutGlobalScope('tenant')->findOrFail($id);
        $this->assertCanAccessTenantRecord($limit);
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
        $this->assertCanAccessTenantRecord($limit);
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
