<?php

declare(strict_types=1);

namespace Modules\FileStores\Console;

use Illuminate\Console\Command;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Jobs\DeleteMediaAsset;
use Modules\FileStores\Jobs\SyncMediaArchive;
use Modules\FileStores\Models\MediaAsset;

/**
 * Requeues failed archive transfers and deletion requests after outages.
 */
class ReconcileMediaAssets extends Command
{
    protected $signature = 'media:reconcile {--retry-failed} {--delete-orphans}';

    protected $description = 'Reconcile failed media archive transfers and orphaned media owners.';

    /**
     * Dispatch recovery work without performing provider I/O in this command.
     */
    public function handle(): int
    {
        if ($this->option('retry-failed')) {
            MediaAsset::withoutGlobalScope('tenant')
                ->where('status', MediaAssetStatus::Failed)
                ->whereNotNull('staging_path')
                ->orderBy('id')
                ->each(fn (MediaAsset $asset): mixed => SyncMediaArchive::dispatch($asset->id));
        }

        if ($this->option('delete-orphans')) {
            MediaAsset::withoutGlobalScope('tenant')
                ->where('status', '!=', MediaAssetStatus::Missing)
                ->orderBy('id')
                ->each(function (MediaAsset $asset): void {
                    if ($asset->owner === null) {
                        DeleteMediaAsset::dispatch($asset->id);
                    }
                });
        }

        return self::SUCCESS;
    }
}
