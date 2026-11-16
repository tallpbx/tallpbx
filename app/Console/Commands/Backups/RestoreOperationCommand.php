<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Backups\Models\RestoreOperation;
use Modules\Backups\Services\RestoreJournal;
use Modules\Backups\Services\RestoreOperationExecutor;
use Modules\Backups\Services\RestoreService;

/**
 * Provides the only Artisan entry point used by the root-owned restore helper.
 *
 * The dispatcher renamed the request marker to `<uuid>.claimed`; this command
 * seeds the root-only journal from that marker before any destructive work, so
 * the operation is recoverable even when the database it replaces is gone.
 */
#[Signature('backups:restore-operation {operation : Restore operation UUID approved for the helper}')]
#[Description('Execute a pending privileged TallPBX restore operation')]
class RestoreOperationCommand extends Command
{
    /**
     * Refuse any operation that was not explicitly prepared for the helper.
     */
    public function handle(
        RestoreOperationExecutor $executor,
        RestoreService $restoreService,
        RestoreJournal $journal,
    ): int {
        $operationId = $this->argument('operation');

        if (! is_string($operationId)) {
            $this->fail('A restore operation UUID is required.');
        }

        $claimPath = $this->claimPath($operationId);

        // A retried stale marker now retires itself instead of failing the
        // unit: the journal already proves the outcome.
        if ($journal->terminalStatus($operationId) !== null) {
            @unlink($claimPath);
            $this->components->info('The restore operation already reached a terminal state.');

            return self::SUCCESS;
        }

        $operation = RestoreOperation::query()->find($operationId);

        if ($operation === null) {
            $this->fail('The restore operation was not found.');
        }

        if ($operation->status !== 'pending-helper') {
            $this->fail('The restore operation has not been prepared for the privileged helper.');
        }

        $metadata = $this->readClaim($claimPath);

        if ($metadata === null) {
            // Starting an unjournalled restore would leave no record once the
            // database is replaced, so refuse instead.
            $this->error('The restore request marker is missing or unreadable.');

            return self::FAILURE;
        }

        $journal->seed($operationId, $metadata);

        try {
            $restoreService->inspectArchive($operation->archive_path, [
                'archive' => ['sha256' => $operation->archive_checksum],
            ]);
        } catch (\Throwable $exception) {
            $journal->record($operationId, 'failed', ['failure_reason' => $exception->getMessage()]);
            @unlink($claimPath);

            $operation->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            $this->error('The restore archive could not be verified.');

            return self::FAILURE;
        }

        $executor->execute($operation);

        @unlink($claimPath);

        return self::SUCCESS;
    }

    /**
     * Return the claimed marker path the dispatcher renamed into place.
     */
    private function claimPath(string $operationId): string
    {
        return rtrim((string) config('backup-storage.request_root'), '/').'/'.$operationId.'.claimed';
    }

    /**
     * Read the marker payload written by the queue job.
     *
     * @return array<string, mixed>|null
     */
    private function readClaim(string $claimPath): ?array
    {
        if (! is_file($claimPath)) {
            return null;
        }

        $contents = @file_get_contents($claimPath);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
