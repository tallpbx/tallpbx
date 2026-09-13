<?php

declare(strict_types=1);

namespace Modules\Backups\Livewire;

use App\Support\BaseEditComponent;
use Modules\Backups\Models\Backup;
use Modules\Backups\Services\BackupServiceInterface;
use Modules\FileStores\Models\FileStore;

/**
 * Livewire form component for creating and editing backup configurations.
 *
 * Provides a form with fields for name, backup scopes, retention count,
 * compression toggle, File Store destination, and optional
 * cron schedule. On save, creates or updates a Backup record and
 * optionally dispatches the first BackupRunner job.
 */
class BackupsEdit extends BaseEditComponent
{
    public string $name = '';

    public array $scope = ['database', 'app_files'];

    public int $retentionCount = 7;

    public bool $compression = true;

    public string $destinationDisk = 'local';

    public ?string $fileStoreId = null;

    /** @var array<string, string> */
    public array $fileStores = [];

    public ?string $scheduleCron = null;

    public ?string $backupId = null;

    private BackupServiceInterface $backupService;

    /**
     * Inject the backup service and load existing configuration.
     */
    public function boot(BackupServiceInterface $backupService): void
    {
        $this->backupService = $backupService;
    }

    /**
     * Load an existing backup configuration for editing, or show empty form.
     */
    public function mount(?string $backupId = null): void
    {
        $this->fileStores = FileStore::query()
            ->where('provider', '!=', 'email')
            ->where(function ($query): void {
                $query->where('provider', '!=', 'local')
                    ->orWhere('name', 'Local storage - backups');
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        if ($backupId !== null) {
            $backup = Backup::findOrFail($backupId);
            $this->backupId = $backup->id;
            $this->name = $backup->name;
            $this->scope = $backup->scope;
            $this->retentionCount = $backup->retention_count;
            $this->compression = $backup->compression;
            $this->destinationDisk = $backup->destination_disk;
            $this->fileStoreId = $backup->file_store_id;
            $this->scheduleCron = $backup->schedule_cron;
        }
    }

    /**
     * Save the backup configuration — create or update.
     *
     * For new configurations, the BackupRunner job is dispatched
     * automatically after creation by BackupService::createBackup().
     */
    public function save(): void
    {
        $validated = $this->validate();
        $fileStore = FileStore::query()
            ->whereKey($validated['fileStoreId'])
            ->where('provider', '!=', 'email')
            ->firstOrFail();
        $validated['file_store_id'] = $fileStore->id;
        unset($validated['fileStoreId']);

        if ($this->backupId !== null) {
            $backup = Backup::findOrFail($this->backupId);
            $this->backupService->updateBackup($backup, $validated);
        } else {
            $backup = $this->backupService->createBackup($validated);
            $this->flashSuccess('Backup “'.$backup->name.'” was created and its first run has been queued.');
        }

        $this->redirect(route('panel.backups.index'));
    }

    /**
     * Available backup scopes for the form checkboxes.
     *
     * @return array<string, string>
     */
    public function availableScopes(): array
    {
        return $this->backupService->availableScopes();
    }

    /**
     * Validation rules for backup configuration.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'array', 'min:1'],
            'scope.*' => ['string', 'in:database,app_files,media,configuration'],
            'retentionCount' => ['required', 'integer', 'min:1', 'max:365'],
            'compression' => ['boolean'],
            'destinationDisk' => ['required', 'string', 'in:local'],
            'fileStoreId' => ['required', 'uuid', 'exists:file_stores,id'],
            'scheduleCron' => ['nullable', 'string', 'max:100'],
        ];
    }
}
