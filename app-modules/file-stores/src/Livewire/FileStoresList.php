<?php

declare(strict_types=1);

namespace Modules\FileStores\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\FileStoreServiceInterface;
use Modules\FileStores\Services\MediaArchiveDestinationServiceInterface;
use Modules\FileStores\Services\MediaStorageServiceInterface;

/**
 * Lists system-owned file store profiles and exposes operational actions.
 */
class FileStoresList extends BaseListComponent
{
    /** @var Collection<int, FileStore> */
    public Collection $fileStores;

    /** @var array<string, string> */
    public array $connectionMessages = [];

    public ?string $deleteError = null;

    public ?string $pendingDeletionFileStoreId = null;

    public string $pendingDeletionFileStoreName = '';

    public string $archiveFileStoreId = '';

    public bool $canUpdateFileStores = false;

    public bool $archiveDestinationSaved = false;

    /** @var Collection<int, FileStore> */
    public Collection $archiveFileStores;

    /** @var Collection<int, MediaAsset> */
    public Collection $archiveTransfers;

    /**
     * Load file store profiles after confirming the system admin guard.
     */
    public function mount(): void
    {
        $this->authorizeSystemAdmin();
        $this->canUpdateFileStores = Auth::guard('admin')->user()?->hasPermission('file-stores.update') ?? false;
        $this->loadFileStores();
        $this->loadArchiveDestination();
        $this->loadArchiveTransfers();
    }

    /**
     * Test the selected profile and retain its non-secret outcome for the list.
     */
    public function testConnection(string $fileStoreId, FileStoreServiceInterface $fileStoreService): void
    {
        $this->authorizeSystemAdmin();
        $fileStore = FileStore::findOrFail($fileStoreId);

        try {
            $fileStoreService->testConnection($fileStore);
            $this->connectionMessages[$fileStore->id] = 'Connection verified.';
            $this->showSuccess('Connection verified for “'.$fileStore->name.'”.');
        } catch (\RuntimeException) {
            $this->connectionMessages[$fileStore->id] = 'Connection could not be verified.';
            $this->showError('Connection could not be verified. Check the server address, credentials, and network access.');
        }
    }

    /**
     * Delete an unused profile without touching its destination contents.
     */
    public function deleteFileStore(string $fileStoreId, FileStoreServiceInterface $fileStoreService): void
    {
        $this->authorizeSystemAdmin();
        $this->deleteError = null;

        try {
            $fileStoreService->delete(FileStore::findOrFail($fileStoreId));
        } catch (\RuntimeException $exception) {
            $this->deleteError = $exception->getMessage();

            return;
        }

        $this->cancelFileStoreDeletion();
        $this->loadFileStores();
        $this->loadArchiveDestination();
    }

    /** Open the styled confirmation dialog for one file store. */
    public function confirmFileStoreDeletion(string $fileStoreId): void
    {
        $this->authorizeSystemAdmin();
        $fileStore = FileStore::findOrFail($fileStoreId);
        $this->deleteError = null;
        $this->pendingDeletionFileStoreId = $fileStore->id;
        $this->pendingDeletionFileStoreName = $fileStore->name;
    }

    /** Close the confirmation dialog without changing the selected profile. */
    public function cancelFileStoreDeletion(): void
    {
        $this->pendingDeletionFileStoreId = null;
        $this->pendingDeletionFileStoreName = '';
        $this->deleteError = null;
    }

    /**
     * Persist the selected readable destination for completed media archives.
     */
    public function updateArchiveDestination(MediaArchiveDestinationServiceInterface $archiveDestination): void
    {
        $this->authorizeSystemAdmin();
        abort_unless(Auth::guard('admin')->user()?->hasPermission('file-stores.update'), 403);

        // Hide a prior confirmation while Livewire validates and saves the
        // newly selected destination.
        $this->archiveDestinationSaved = false;

        $this->validate([
            'archiveFileStoreId' => ['required', 'uuid', 'exists:file_stores,id'],
        ]);

        $fileStore = FileStore::query()
            ->where('provider', '!=', 'email')
            ->findOrFail($this->archiveFileStoreId);

        try {
            $archiveDestination->set($fileStore);
        } catch (\RuntimeException $exception) {
            $this->showError($exception->getMessage());

            return;
        }

        $this->loadArchiveDestination();
        $this->archiveDestinationSaved = true;
        $this->showSuccess('Media archive destination saved.');
    }

    /** Requeue one retained failed media archive after a system administrator reviews it. */
    public function retryMediaArchive(string $mediaAssetId, MediaStorageServiceInterface $mediaStorage): void
    {
        $this->authorizeSystemAdmin();
        abort_unless(Auth::guard('admin')->user()?->hasPermission('file-stores.update'), 403);

        try {
            $mediaStorage->retryArchive($mediaAssetId);
        } catch (\RuntimeException $exception) {
            $this->showError($exception->getMessage());

            return;
        }

        $this->showInfo('Media archive retry has been queued.');
        $this->loadArchiveTransfers();
    }

    /**
     * Load profiles in a stable order for predictable administration.
     */
    private function loadFileStores(): void
    {
        $this->fileStores = FileStore::query()->where('provider', '!=', 'local')->orderBy('name')->get();
    }

    /**
     * Load the selected archive destination and all readable choices.
     */
    private function loadArchiveDestination(): void
    {
        $this->archiveFileStores = FileStore::query()
            ->where('provider', '!=', 'email')
            ->where(function ($query): void {
                $query->where('provider', '!=', 'local')
                    ->orWhere('name', 'Local storage - media');
            })
            ->orderBy('name')
            ->get();
        $this->archiveFileStoreId = app(MediaArchiveDestinationServiceInterface::class)->current()->id;
    }

    /** Load the recent non-terminal media archive transfers without exposing provider error details. */
    private function loadArchiveTransfers(): void
    {
        $this->archiveTransfers = MediaAsset::withoutGlobalScope('tenant')
            ->with('fileStore')
            ->whereIn('status', [
                MediaAssetStatus::Pending->value,
                MediaAssetStatus::Transferring->value,
                MediaAssetStatus::Failed->value,
            ])
            ->latest('updated_at')
            ->limit(25)
            ->get();
    }

    /**
     * Require the dedicated system-admin guard for every Livewire action.
     */
    private function authorizeSystemAdmin(): void
    {
        abort_unless(Auth::guard('admin')->check(), 403);
    }
}
