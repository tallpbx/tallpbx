<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Tenant;
use App\Services\TenantServiceInterface;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Livewire component that lists all tenants with CRUD actions.
 *
 * Admin-only access. Shows each tenant's name, slug,
 * primary user, enabled status, and user count. Tenant deletion
 * requires typing the tenant name into the shared confirmation modal.
 */
#[Layout('layouts.app')]
class TenantsList extends Component
{
    use HasOperationalFeedback;

    /** @var Collection<int, Tenant> */
    public Collection $tenants;

    public ?int $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    /** The text typed by the administrator in the confirmation input. */
    public string $confirmTypedInput = '';

    private TenantServiceInterface $tenantService;

    public function boot(TenantServiceInterface $tenantService): void
    {
        $this->tenantService = $tenantService;
    }

    public function mount(): void
    {
        $this->loadTenants();
    }

    /**
     * Load all tenants with relationships.
     */
    private function loadTenants(): void
    {
        $this->tenants = $this->tenantService->all();
    }

    /** Open the typed confirmation modal for one tenant. */
    public function confirmTenantDeletion(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);
        $this->pendingDeletionId = $tenant->id;
        $this->pendingDeletionName = $tenant->name;
        $this->deleteError = null;
        $this->confirmTypedInput = '';
    }

    /** Close the tenant confirmation modal without deleting anything. */
    public function cancelTenantDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName', 'deleteError', 'confirmTypedInput');
    }

    /**
     * Delete the tenant after the typed name matches server-side.
     *
     * The typed match is the authoritative gate; the disabled confirm
     * button in the modal is convenience only.
     */
    public function deleteTenant(): void
    {
        if ($this->pendingDeletionId === null) {
            return;
        }

        if (trim($this->confirmTypedInput) !== $this->pendingDeletionName) {
            $this->deleteError = 'The typed text does not match. Nothing was changed.';

            return;
        }

        $tenant = Tenant::findOrFail($this->pendingDeletionId);

        try {
            $this->tenantService->delete($tenant);
        } catch (RuntimeException $exception) {
            $this->deleteError = 'Tenant could not be deleted. '.$exception->getMessage();

            return;
        }

        $this->cancelTenantDeletion();
        $this->loadTenants();
        $this->showSuccess('Tenant deleted.');
        $this->dispatch('tenant-deleted');
    }

    public function render(): View
    {
        // Reload with eager-loaded relations: Livewire re-hydrates the serialized
        // collection without loaded relations, which would lazy-load (and fail).
        $this->loadTenants();

        return view('admin::tenants-list');
    }
}
