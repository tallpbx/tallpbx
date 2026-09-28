<?php

declare(strict_types=1);

namespace Modules\Provision\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Provision\Models\ProvisionTemplate;
use Modules\Provision\Services\ProvisionServiceInterface;

/**
 * Livewire component listing provisioning templates with a delete action.
 */
#[Layout('layouts.app')]
class TemplateList extends BaseListComponent
{
    /** @var Collection<int, ProvisionTemplate> */
    public Collection $templates;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private ProvisionServiceInterface $service;

    /**
     * Inject the provision service used by this component.
     */
    public function boot(ProvisionServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Load the template list when the page opens.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch templates ordered by name. Administrators see every
     * tenant's templates; tenant users only see their own.
     */
    private function load(): void
    {
        $query = $this->isAdminGuard()
            ? ProvisionTemplate::withoutGlobalScope('tenant')
            : ProvisionTemplate::query();

        $this->templates = $query->orderBy('name')->get();
    }

    /** Open the shared confirmation modal for a provisioning template. */
    public function confirmTemplateDeletion(string $id): void
    {
        $template = ProvisionTemplate::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $template->id;
        $this->pendingDeletionName = $template->name;
    }

    /** Close the provisioning-template confirmation modal without deleting anything. */
    public function cancelTemplateDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the provisioning template that the user confirmed. */
    public function deleteTemplate(): void
    {
        $t = ProvisionTemplate::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->service->deleteTemplate($t);
        $this->cancelTemplateDeletion();
        $this->load();
        $this->showSuccess('Template deleted.');
        $this->dispatch('template-deleted');
    }
}
