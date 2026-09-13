<?php

declare(strict_types=1);

namespace Modules\FeatureCodes\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\FeatureCodes\Services\FeatureCodeServiceInterface;

/**
 * Livewire component that lists feature codes with CRUD actions.
 */
class FeatureCodesList extends BaseListComponent
{
    /** @var Collection<int, FeatureCode> */
    public Collection $codes;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private FeatureCodeServiceInterface $featureCodeService;

    /**
     * Boot the component with the feature code service.
     */
    public function boot(FeatureCodeServiceInterface $featureCodeService): void
    {
        $this->featureCodeService = $featureCodeService;
    }

    /**
     * Mount the component and load feature codes.
     */
    public function mount(): void
    {
        $this->loadCodes();
    }

    /**
     * Load all feature codes ordered by name.
     */
    private function loadCodes(): void
    {
        $this->codes = FeatureCode::withoutGlobalScope('tenant')->orderBy('name')->get();
    }

    /**
     * Delete a feature code by its ID.
     */
    public function deleteCode(string $codeId): void
    {
        $code = FeatureCode::withoutGlobalScope('tenant')->findOrFail($codeId);
        $this->featureCodeService->delete($code);
        $this->cancelCodeDeletion();
        $this->loadCodes();
        $this->showSuccess('Feature code deleted.');
        $this->dispatch('code-deleted');
    }

    /** Open the shared deletion confirmation for a feature code. */
    public function confirmCodeDeletion(string $codeId): void
    {
        $code = FeatureCode::withoutGlobalScope('tenant')->findOrFail($codeId);
        $this->pendingDeletionId = $code->id;
        $this->pendingDeletionName = $code->name;
    }

    /** Close the feature-code deletion confirmation. */
    public function cancelCodeDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
