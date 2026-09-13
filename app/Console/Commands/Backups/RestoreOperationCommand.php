<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Backups\Models\RestoreOperation;
use Modules\Backups\Services\RestoreOperationExecutor;
use Modules\Backups\Services\RestoreService;

/**
 * Provides the only Artisan entry point used by the root-owned restore helper.
 */
#[Signature('backups:restore-operation {operation : Restore operation UUID approved for the helper}')]
#[Description('Execute a pending privileged TallPBX restore operation')]
class RestoreOperationCommand extends Command
{
    /**
     * Refuse any operation that was not explicitly prepared for the helper.
     */
    public function handle(RestoreOperationExecutor $executor, RestoreService $restoreService): int
    {
        $operationId = $this->argument('operation');

        if (! is_string($operationId)) {
            $this->fail('A restore operation UUID is required.');
        }

        $operation = RestoreOperation::query()->find($operationId);

        if ($operation === null) {
            $this->fail('The restore operation was not found.');
        }

        if ($operation->status !== 'pending-helper') {
            $this->fail('The restore operation has not been prepared for the privileged helper.');
        }

        try {
            $restoreService->inspectArchive($operation->archive_path, [
                'archive' => ['sha256' => $operation->archive_checksum],
            ]);
        } catch (\Throwable $exception) {
            $operation->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            $this->error('The restore archive could not be verified.');

            return self::FAILURE;
        }

        $executor->execute($operation);

        return self::SUCCESS;
    }
}
