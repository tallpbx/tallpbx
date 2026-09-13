<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantDomainServiceInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule as LivewireRule;
use Livewire\Component;

/**
 * Livewire component for creating and editing tenant domains.
 *
 * Admin-only access. Handles domain CRUD with tenant
 * assignment and purpose selection (sip_realm, provisioning,
 * web, alias).
 */
#[Layout('layouts.app')]
class TenantDomainsEdit extends Component
{
    #[LivewireRule('required|string|max:255')]
    public string $domain = '';

    #[LivewireRule('required|string|in:sip_realm,provisioning,web,alias')]
    public string $purpose = 'sip_realm';

    #[LivewireRule('required|integer|exists:tenants,id')]
    public ?int $tenantId = null;

    public bool $enabled = true;

    public ?string $domainId = null;

    /** @var Collection<int, Tenant> */
    public Collection $tenants;

    private TenantDomainServiceInterface $domainService;

    public function boot(TenantDomainServiceInterface $domainService): void
    {
        $this->domainService = $domainService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?string $domainId = null): void
    {
        $this->tenants = Tenant::orderBy('name')->get();

        if ($domainId !== null) {
            $this->domainId = $domainId;
            $domain = TenantDomain::findOrFail($domainId);
            $this->domain = $domain->domain;
            $this->tenantId = $domain->tenant_id;
            $this->purpose = $domain->purpose;
            $this->enabled = $domain->enabled;
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->domainId !== null;
    }

    /**
     * Save the tenant domain — creates or updates.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'domain' => $this->domain,
            'tenant_id' => $this->tenantId,
            'purpose' => $this->purpose,
            'enabled' => $this->enabled,
        ];

        if ($this->domainId !== null) {
            $domain = TenantDomain::findOrFail($this->domainId);
            $this->domainService->update($domain, $data);
        } else {
            $this->domainService->create($data);
        }

        $this->redirect(route('panel.tenant-domains.index'));
    }

    /**
     * Validation rules with unique domain constraint.
     */
    protected function rules(): array
    {
        $uniqueRule = Rule::unique('tenant_domains', 'domain')
            ->ignore($this->domainId);

        return [
            'domain' => ['required', 'string', 'max:255', $uniqueRule],
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'purpose' => ['required', 'string', 'in:sip_realm,provisioning,web,alias'],
        ];
    }

    public function render(): View
    {
        return view('admin::tenant-domains-edit');
    }
}
