<?php

declare(strict_types=1);

namespace Modules\Extensions\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Modules\Extensions\Models\Extension;
use Modules\Extensions\Services\ExtensionServiceInterface;

/**
 * Livewire component that lists extensions with CRUD actions.
 */
class ExtensionsList extends BaseListComponent
{
    use WithPagination;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private ExtensionServiceInterface $extensionService;

    /**
     * Boot the component with the extension service.
     */
    public function boot(ExtensionServiceInterface $extensionService): void
    {
        $this->extensionService = $extensionService;
    }

    /**
     * Load all extensions ordered by extension number.
     */
    #[Computed]
    public function extensions(): LengthAwarePaginator
    {
        $query = $this->isAdminGuard()
            ? Extension::withoutGlobalScope('tenant')
            : Extension::query();

        return $query->orderBy('extension_number')->paginate(25);
    }

    /** Open the shared confirmation modal for an extension. */
    public function confirmExtensionDeletion(string $extensionId): void
    {
        $extension = Extension::withoutGlobalScope('tenant')->findOrFail($extensionId);
        $this->assertCanAccessTenantRecord($extension);
        $this->pendingDeletionId = $extension->id;
        $this->pendingDeletionName = $extension->extension_number;
    }

    /** Close the extension confirmation modal without deleting anything. */
    public function cancelExtensionDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the extension that the user confirmed. */
    public function deleteExtension(): void
    {
        $extension = Extension::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->assertCanAccessTenantRecord($extension);
        $this->extensionService->delete($extension);
        $this->cancelExtensionDeletion();
        $this->showSuccess('Extension deleted.');
        $this->dispatch('extension-deleted');
    }

    /**
     * Render the list component.
     */
    public function render(): View
    {
        return view('extensions::extensions-list', [
            'extensions' => $this->extensions,
        ])->layout('layouts.app');
    }
}
