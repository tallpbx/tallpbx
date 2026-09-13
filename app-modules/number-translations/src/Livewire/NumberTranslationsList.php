<?php

declare(strict_types=1);

namespace Modules\NumberTranslations\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\NumberTranslations\Models\NumberTranslation;
use Modules\NumberTranslations\Services\NumberTranslationServiceInterface;

class NumberTranslationsList extends BaseListComponent
{
    /** @var Collection<int, NumberTranslation> */
    public Collection $translations;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private NumberTranslationServiceInterface $service;

    public function boot(NumberTranslationServiceInterface $service): void
    {
        $this->service = $service;
    }

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->translations = NumberTranslation::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    public function deleteTranslation(string $id): void
    {
        $translation = NumberTranslation::withoutGlobalScope('tenant')->findOrFail($id);
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
