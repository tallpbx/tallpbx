<?php

declare(strict_types=1);

namespace Modules\Backups\Listeners;

use Modules\Backups\Models\Backup;
use Modules\FileStores\Events\FileStoreDeleting;
use RuntimeException;

/**
 * Refuses to delete a file store that backup profiles still use.
 *
 * The listener throws the exact user-facing error the file stores module
 * used to raise itself, so the panel shows an identical message while the
 * dependency direction stays backups -> file-stores.
 */
class BlockFileStoreDeletion
{
    /**
     * Block the deletion when any backup profile references the store.
     */
    public function handle(FileStoreDeleting $event): void
    {
        $backupNames = Backup::query()
            ->where('file_store_id', $event->fileStore->id)
            ->orderBy('name')
            ->pluck('name');

        if ($backupNames->isEmpty()) {
            return;
        }

        $backupList = $backupNames->map(fn (string $name): string => '“'.$name.'”')->join(', ');

        throw new RuntimeException('Cannot delete “'.$event->fileStore->name.'” because it is used by backup '.$backupList.'. Delete or move that backup first.');
    }
}
