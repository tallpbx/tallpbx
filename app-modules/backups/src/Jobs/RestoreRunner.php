<?php

declare(strict_types=1);

namespace Modules\Backups\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Modules\Backups\Models\RestoreOperation;

/**
 * Moves a verified restore request into the state consumed by the root helper.
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
        RestoreOperation::query()->findOrFail($this->operationId)->update(['status' => 'pending-helper']);

        $requestDirectory = '/var/lib/tallpbx/restore-requests';
        File::ensureDirectoryExists($requestDirectory, 0770, true);
        File::put($requestDirectory.'/'.$this->operationId.'.request', '');
    }
}
