<?php

declare(strict_types=1);

namespace Modules\Backups\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Modules\Backups\Jobs\BackupRunner;
use Modules\Backups\Models\Backup;
use Modules\Backups\Services\BackupServiceInterface;

/**
 * Livewire component listing all backup configurations.
 *
 * Displays a table of configured backups with status, last run time,
 * size, and actions (run now, edit, delete, restore). Admins can
 * create new backup configurations from this screen.
 */
class BackupsList extends BaseListComponent
{
    /** @var Collection<int, Backup> */
    public Collection $backups;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public ?string $deleteError = null;

    public ?string $pendingRunId = null;

    public string $pendingRunName = '';

    /**
     * Load all backup configurations ordered by creation date.
     */
    public function mount(): void
    {
        $this->loadBackups();
        $this->consumeOperationalFeedback();
    }

    /** Open the shared confirmation modal for the run-now action. */
    public function confirmRunNow(string $backupId): void
    {
        $backup = Backup::findOrFail($backupId);
        $this->pendingRunId = $backup->id;
        $this->pendingRunName = $backup->name;
    }

    /** Close the run-now confirmation without queueing anything. */
    public function cancelRunNow(): void
    {
        $this->pendingRunId = null;
        $this->pendingRunName = '';
    }

    /**
     * Dispatch a backup runner job for immediate execution.
     *
     * Sets status to pending, then fires the queued BackupRunner job
     * which calls BackupService::run().
     */
    public function runNow(): void
    {
        // Guard against a stale or double-fired confirmation: without a pending
        // run, this must be a no-op instead of a 500 on a missing backup.
        if ($this->pendingRunId === null) {
            return;
        }

        $backup = Backup::findOrFail($this->pendingRunId);
        $backup->update(['status' => 'pending']);
        BackupRunner::dispatch($backup);
        $this->cancelRunNow();
        $this->showSuccess('Backup run queued.');
        $this->loadBackups();
    }

    /**
     * Delete a backup configuration and its stored files.
     *
     * Uses the BackupService to remove all stored files from the
     * destination disk before deleting the database record.
     */
    public function deleteBackup(string $backupId): void
    {
        $backup = Backup::findOrFail($backupId);

        /** @var BackupServiceInterface $service */
        $service = app(BackupServiceInterface::class);
        try {
            $service->deleteBackup($backup);
        } catch (\RuntimeException $exception) {
            $this->deleteError = 'Backup could not be deleted. '.$exception->getMessage();

            if ($this->pendingDeletionId === null) {
                $this->showError($this->deleteError);
            }

            return;
        }

        $this->cancelBackupDeletion();
        $this->showSuccess('Backup “'.$backup->name.'” was deleted.');
        $this->loadBackups();
    }

    /** Open the shared destructive-action confirmation for one backup. */
    public function confirmBackupDeletion(string $backupId): void
    {
        $backup = Backup::findOrFail($backupId);
        $this->pendingDeletionId = $backup->id;
        $this->pendingDeletionName = $backup->name;
        $this->deleteError = null;
    }

    /** Close the backup deletion confirmation without changing the backup. */
    public function cancelBackupDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
        $this->deleteError = null;
    }

    /** Load backup configurations in a stable order after an operational action. */
    private function loadBackups(): void
    {
        $this->backups = Backup::orderBy('created_at', 'desc')->get();
    }

    /**
     * Render the backups list view.
     */
    public function render(): View
    {
        return view('backups::backups-list', [
            'backups' => $this->backups,
        ]);
    }
}
