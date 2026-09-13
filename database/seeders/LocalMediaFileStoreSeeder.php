<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\FileStores\Models\FileStore;
use RuntimeException;

/**
 * Seeds the dedicated local destination for TallPBX-managed media.
 */
class LocalMediaFileStoreSeeder extends Seeder
{
    /**
     * Create the local media destination without reusing the backup store.
     */
    public function run(): void
    {
        $fileStore = FileStore::query()->firstOrCreate(
            ['name' => 'Local storage - media'],
            [
                'provider' => 'local',
                'settings' => ['root' => config('media-storage.store_root')],
            ],
        );

        if ($fileStore->provider !== 'local'
            || ($fileStore->settings['root'] ?? null) !== config('media-storage.store_root')) {
            throw new RuntimeException('The reserved Local storage - media File Store has an incompatible configuration.');
        }
    }
}
