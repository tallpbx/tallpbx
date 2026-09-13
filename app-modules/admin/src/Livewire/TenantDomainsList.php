<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\TenantDomain;
use App\Services\TenantDomainServiceInterface;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Livewire component that lists all tenant domains with CRUD actions.
 *
 * Admin-only access. Shows domain, tenant, purpose,
 * and enabled status for each domain record.
 */
#[Layout('layouts.app')]
class TenantDomainsList extends Component
{
    use HasOperationalFeedback;

    /** @var Collection<int, TenantDomain> */
    public Collection $domains;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    private TenantDomainServiceInterface $domainService;

    public function boot(TenantDomainServiceInterface $domainService): void
    {
        $this->domainService = $domainService;
    }

    public function mount(): void
    {
        $this->loadDomains();
    }

    /**
     * Load all tenant domains with tenant relationship.
     */
    private function loadDomains(): void
    {
        $this->domains = $this->domainService->all();
    }

    /** Open the shared confirmation modal for one tenant domain. */
    public function confirmDomainDeletion(string $domainId): void
    {
        $domain = TenantDomain::findOrFail($domainId);
        $this->pendingDeletionId = $domain->id;
        $this->pendingDeletionName = $domain->domain;
        $this->deleteError = null;
    }

    /** Close the domain deletion confirmation without deleting. */
    public function cancelDomainDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName', 'deleteError');
    }

    /** Delete the tenant domain that the user confirmed, keeping expected failures in the modal. */
    public function deleteDomain(): void
    {
        if ($this->pendingDeletionId === null) {
            return;
        }

        $domain = TenantDomain::findOrFail($this->pendingDeletionId);

        try {
            $this->domainService->delete($domain);
        } catch (RuntimeException $exception) {
            $this->deleteError = 'Domain could not be deleted. '.$exception->getMessage();

            return;
        }

        $this->cancelDomainDeletion();
        $this->loadDomains();
        $this->showSuccess('Domain deleted.');
        $this->dispatch('domain-deleted');
    }

    public function render(): View
    {
        return view('admin::tenant-domains-list');
    }
}
