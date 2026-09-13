<?php

declare(strict_types=1);

namespace Modules\Backups\Livewire;

use App\Models\Admin;
use App\Support\BaseEditComponent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Backups\Models\Backup;
use Modules\Backups\Models\RestoreOperation;
use Modules\Backups\Services\RestoreService;
use Modules\FileStores\Services\FileStoreServiceInterface;
use RuntimeException;

/**
 * Lets a super administrator inspect and submit a verified backup restore.
 */
class RestoreArchive extends BaseEditComponent
{
    use WithFileUploads;

    /** @var Collection<int, Backup> */
    public Collection $backups;

    /** The completed backup selected from a configured File Store. */
    public ?string $backupId = null;

    /** Whether the typed restore confirmation modal is open. */
    public bool $confirmingRestore = false;

    /** The archive basename the administrator must type to confirm. */
    public ?string $pendingRestoreName = null;

    /** Which restore form opened the confirmation: 'stored' or 'uploaded'. */
    public string $pendingRestoreSource = 'stored';

    /** The text typed into the restore confirmation modal. */
    public string $confirmTypedInput = '';

    /** The safe in-modal error shown when the restore is not accepted. */
    public ?string $restoreError = null;

    /** The archive file uploaded directly to this server. */
    public ?TemporaryUploadedFile $archiveUpload = null;

    /** The manifest uploaded alongside a direct archive upload. */
    public ?TemporaryUploadedFile $manifestUpload = null;

    /** @var array<string, mixed> */
    public array $selectedManifest = [];

    /** The durable restore operation currently being monitored. */
    public ?string $operationId = null;

    /**
     * Load completed backups that can be restored from their configured File Store.
     */
    public function mount(): void
    {
        $this->loadRestorableBackups();
    }

    /**
     * Reload relations that Livewire does not retain between panel requests.
     */
    public function hydrate(): void
    {
        $this->loadRestorableBackups();
    }

    /**
     * Clear an inspected manifest and any open confirmation when the File Store selection changes.
     */
    public function updatedBackupId(): void
    {
        $this->selectedManifest = [];
        $this->confirmingRestore = false;
        $this->pendingRestoreName = null;
        $this->pendingRestoreSource = 'stored';
        $this->confirmTypedInput = '';
        $this->restoreError = null;
    }

    /**
     * Inspect a selected File Store archive and open the typed restore confirmation.
     */
    public function confirmStoredRestore(FileStoreServiceInterface $fileStoreService): void
    {
        $this->validateOnly('backupId', ['backupId' => ['required', 'uuid']]);
        try {
            $backup = $this->selectedBackup();
            $this->selectedManifest = $this->readManifest($backup, $fileStoreService);
            $this->openConfirmationModal(basename($backup->last_file_path), 'stored');
        } catch (RuntimeException $exception) {
            $this->showError($exception->getMessage());
        }
    }

    /**
     * Validate a direct upload and open the typed restore confirmation.
     */
    public function confirmUploadedRestore(): void
    {
        $this->validate([
            'archiveUpload' => ['required', 'file', 'max:1048576'],
            'manifestUpload' => ['required', 'file', 'max:1024'],
        ]);

        try {
            $this->selectedManifest = $this->decodeManifest((string) file_get_contents($this->manifestUpload->getRealPath()));
            $this->openConfirmationModal(basename($this->archiveUpload->getClientOriginalName()), 'uploaded');
        } catch (RuntimeException $exception) {
            $this->showError($exception->getMessage());
        }
    }

    /**
     * Open the typed confirmation modal for the given archive name and source form.
     */
    private function openConfirmationModal(string $archiveName, string $source): void
    {
        $this->pendingRestoreName = $archiveName;
        $this->pendingRestoreSource = $source;
        $this->confirmTypedInput = '';
        $this->restoreError = null;
        $this->confirmingRestore = true;
    }

    /**
     * Close the restore confirmation modal without restoring anything.
     */
    public function cancelRestore(): void
    {
        $this->confirmingRestore = false;
        $this->pendingRestoreName = null;
        $this->pendingRestoreSource = 'stored';
        $this->confirmTypedInput = '';
        $this->restoreError = null;
    }

    /**
     * Confirm the typed archive name and request the privileged restore.
     *
     * The typed match is the authoritative gate: nothing is staged until
     * the administrator types the exact archive name. Any curated failure
     * keeps the modal open with a safe in-modal error.
     */
    public function requestRestore(RestoreService $restoreService): void
    {
        if ($this->pendingRestoreName === null || trim($this->confirmTypedInput) !== $this->pendingRestoreName) {
            $this->restoreError = 'The typed text does not match. Nothing was changed.';

            return;
        }

        try {
            $stagedPath = $this->pendingRestoreSource === 'uploaded'
                ? $this->stageUploadedArchive()
                : $this->stageStoredArchive($restoreService);

            $this->performRestore($restoreService, $stagedPath, $this->selectedManifest);
        } catch (RuntimeException $exception) {
            $this->restoreError = $exception->getMessage();
        }
    }

    /**
     * Stage the selected File Store archive into private restore staging.
     */
    private function stageStoredArchive(RestoreService $restoreService): string
    {
        $backup = $this->selectedBackup();

        return $restoreService->stageArchive(
            $backup->fileStore,
            $backup->last_file_path,
            rtrim((string) config('backup-storage.root'), '/').'/restore-staging',
        );
    }

    /**
     * Copy a direct upload into private restore staging.
     */
    private function stageUploadedArchive(): string
    {
        $archiveName = basename($this->archiveUpload->getClientOriginalName());
        $stagingPath = rtrim((string) config('backup-storage.root'), '/').'/restore-staging/'.Str::uuid();
        File::ensureDirectoryExists($stagingPath, 0700, true);
        $stagedPath = $stagingPath.'/'.$archiveName;
        File::copy($this->archiveUpload->getRealPath(), $stagedPath);
        chmod($stagedPath, 0600);

        return $stagedPath;
    }

    /**
     * Authorize the current administrator and create the immutable restore operation.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function performRestore(RestoreService $restoreService, string $archivePath, array $manifest): void
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin || ! $this->isSuperAdministrator) {
            abort(403);
        }

        $this->operationId = $restoreService->requestRestore($admin, $archivePath, $this->confirmTypedInput, $manifest)->id;
        $this->showSuccess('Restore request accepted. TallPBX is preparing the verified archive.');
        $this->cancelRestore();
    }

    /**
     * Resolve the current operation on each poll so its status is always fresh.
     */
    public function getOperationProperty(): ?RestoreOperation
    {
        return $this->operationId === null
            ? null
            : RestoreOperation::query()->find($this->operationId);
    }

    /**
     * Determine whether the current administrator belongs to the superadmin group.
     */
    public function getIsSuperAdministratorProperty(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin
            && $admin->groups()->where('name', 'Super Administrators')->exists();
    }

    /**
     * Resolve the selected completed backup and ensure it still has a File Store.
     */
    private function selectedBackup(): Backup
    {
        $backup = Backup::query()->with('fileStore')->findOrFail($this->backupId);

        if ($backup->status !== 'completed' || $backup->fileStore === null || $backup->last_file_path === null || $backup->manifest_path === null) {
            throw new RuntimeException('The selected backup is no longer available for restore.');
        }

        return $backup;
    }

    /**
     * Load completed backup records together with their File Store destination.
     */
    private function loadRestorableBackups(): void
    {
        $this->backups = Backup::query()
            ->with('fileStore')
            ->where('status', 'completed')
            ->whereNotNull('file_store_id')
            ->whereNotNull('last_file_path')
            ->whereNotNull('manifest_path')
            ->orderByDesc('last_success_at')
            ->get();
    }

    /**
     * Read and decode the manifest stored next to a completed File Store archive.
     *
     * @return array<string, mixed>
     */
    private function readManifest(Backup $backup, FileStoreServiceInterface $fileStoreService): array
    {
        $stream = $fileStoreService->readStream($backup->fileStore, $backup->manifest_path);

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if ($contents === false) {
            throw new RuntimeException('The selected backup manifest could not be read.');
        }

        return $this->decodeManifest($contents);
    }

    /**
     * Validate the minimal portable manifest contract needed before staging an archive.
     *
     * @return array<string, mixed>
     */
    private function decodeManifest(string $contents): array
    {
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('The selected restore manifest is not valid JSON.');
        }

        if (! is_array($manifest)
            || ! isset($manifest['archive']['sha256'], $manifest['scopes'])
            || ! is_string($manifest['archive']['sha256'])
            || ! preg_match('/^[a-f0-9]{64}$/i', $manifest['archive']['sha256'])
            || ! is_array($manifest['scopes'])
            || $manifest['scopes'] === []) {
            throw new RuntimeException('The selected restore manifest is incomplete or incompatible.');
        }

        return $manifest;
    }
}
