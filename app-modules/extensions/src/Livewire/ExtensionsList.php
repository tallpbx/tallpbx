<?php

declare(strict_types=1);

namespace Modules\Extensions\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\Extensions\Models\Extension;
use Modules\Extensions\Services\ExtensionServiceInterface;

/**
 * Livewire component that lists extensions with CRUD actions.
 */
class ExtensionsList extends BaseListComponent
{
    /** @var Collection<int, Extension> */
    public Collection $extensions;

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
     * Mount the component and load extensions.
     */
    public function mount(): void
    {
        $this->loadExtensions();
    }

    /**
     * Load all extensions ordered by extension number.
     */
    private function loadExtensions(): void
    {
        $this->extensions = Extension::withoutGlobalScope('tenant')->orderBy('extension_number')->get();
    }

    /** Open the shared confirmation modal for an extension. */
    public function confirmExtensionDeletion(string $extensionId): void
    {
        $extension = Extension::withoutGlobalScope('tenant')->findOrFail($extensionId);
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
        $this->extensionService->delete($extension);
        $this->cancelExtensionDeletion();
        $this->loadExtensions();
        $this->showSuccess('Extension deleted.');
        $this->dispatch('extension-deleted');
    }

    /**
     * Render the list component.
     */
}
