<?php

declare(strict_types=1);

namespace Modules\NumberTranslations\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\NumberTranslations\Models\NumberTranslation;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;

/**
 * Livewire component listing number translation rules with a delete action.
 */
class NumberTranslationsList extends BaseListComponent
{
    /** @var Collection<int, NumberTranslation> */
    public Collection $translations;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private NumberTranslationServiceInterface $service;

    /**
     * Inject the number translation service used by this component.
     */
    public function boot(NumberTranslationServiceInterface $service): void
    {
        $this->service = $service;
    }

    /**
     * Load the translation list when the page opens.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch translation rules ordered by name. Administrators see every
     * tenant's rules; tenant users only see their own.
     */
    private function load(): void
    {
        $query = $this->isAdminGuard()
            ? NumberTranslation::withoutGlobalScope('tenant')
            : NumberTranslation::query();

        $this->translations = $query->orderBy('name')->get();
    }

    /**
     * Delete the confirmed translation rule and refresh the list.
     */
    public function deleteTranslation(string $id): void
    {
        $translation = NumberTranslation::withoutGlobalScope('tenant')->findOrFail($id);
        $this->assertCanAccessTenantRecord($translation);
        $this->service->delete($translation);
        $this->cancelTranslationDeletion();
        $this->load();
        $this->showSuccess('Number translation deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }

    /** Open the shared deletion confirmation for a number translation. */
    public function confirmTranslationDeletion(string $id): void
    {
        $translation = NumberTranslation::withoutGlobalScope('tenant')->findOrFail($id);
        $this->assertCanAccessTenantRecord($translation);
        $this->pendingDeletionId = $translation->id;
        $this->pendingDeletionName = $translation->name;
    }

    /** Close the number-translation deletion confirmation. */
    public function cancelTranslationDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
