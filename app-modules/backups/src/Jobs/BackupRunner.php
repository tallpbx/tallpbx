<?php

declare(strict_types=1);

namespace Modules\Backups\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Backups\Models\Backup;
use Modules\Backups\Services\BackupServiceInterface;

/**
 * Queued job that executes a backup for a given configuration.
 *
 * Dispatched after a backup is created or when an admin clicks "Run Now"
 * from the backups list. The job calls BackupService::run() which
 * orchestrates the full backup pipeline.
 */
class BackupRunner implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job to run a specific backup configuration.
     */
    public function __construct(
        private readonly Backup $backup,
    ) {}

    /**
     * Execute the backup via the BackupService.
     *
     * The service handles the full pipeline: database dump, file archive,
     * compression, storage, and retention pruning. Status and
     * errors are written directly to the Backup model by the service.
     */
    public function handle(BackupServiceInterface $service): void
    {
        $service->run($this->backup);
    }
}
