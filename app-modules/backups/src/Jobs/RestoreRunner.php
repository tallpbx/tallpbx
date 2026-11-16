<?php

declare(strict_types=1);

namespace Modules\Backups\Jobs;

use App\Models\Admin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Modules\Backups\Models\RestoreOperation;

/**
 * Moves a verified restore request into the state consumed by the root helper.
 *
 * This job runs as `www-data` and therefore never writes the root-only journal.
 * It hands the helper everything the journal needs on the request marker: the
 * immutable archive facts and a snapshot of the requesting administrator, so a
 * restore that replaces the `admins` table can still name who asked for it.
 */
class RestoreRunner implements ShouldQueue
{
    use Queueable;

    /**
     * Create a queued handoff for one immutable restore operation.
     */
    public function __construct(private readonly string $operationId) {}

    /**
     * Mark the operation ready without executing any privileged process.
     */
    public function handle(): void
    {
        $operation = RestoreOperation::query()->findOrFail($this->operationId);

        $operation->update(['status' => 'pending-helper']);

        $requester = $operation->requested_by_admin_id === null
            ? null
            : Admin::query()->find($operation->requested_by_admin_id);

        $requestDirectory = (string) config('backup-storage.request_root');
        File::ensureDirectoryExists($requestDirectory, 0770, true);

        File::put(
            $requestDirectory.'/'.$this->operationId.'.request',
            (string) json_encode([
                'archive_path' => $operation->archive_path,
                'archive_checksum' => $operation->archive_checksum,
                'source_backup_id' => $operation->source_backup_id,
                'scopes' => $operation->scopes,
                'requester' => $requester === null ? null : [
                    'id' => $requester->id,
                    'email' => $requester->email,
                    'name' => $requester->name,
                ],
                'started_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
