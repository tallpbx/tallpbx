<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Backups\Models\Backup;
use Modules\FileStores\Models\FileStore;

return new class extends Migration
{
    /**
     * Backfill legacy local backups to the idempotent local host file store.
     */
    public function up(): void
    {
        $fileStore = FileStore::query()->firstOrCreate(
            ['name' => 'Local host'],
            [
                'provider' => 'local',
                'settings' => ['root' => storage_path('app/backups')],
            ],
        );

        Backup::query()
            ->whereNull('file_store_id')
            ->where('destination_disk', 'local')
            ->update(['file_store_id' => $fileStore->id]);
    }

    /**
     * Keep the backfill because legacy backup records should remain addressable.
     */
    public function down(): void {}
};
