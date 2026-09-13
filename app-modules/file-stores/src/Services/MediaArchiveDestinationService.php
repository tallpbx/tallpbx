<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use App\Services\SettingServiceInterface;
use Modules\FileStores\Models\FileStore;
use RuntimeException;

/**
 * Stores and resolves the system-wide media archive destination.
 */
class MediaArchiveDestinationService implements MediaArchiveDestinationServiceInterface
{
    private const SettingKey = 'media.archive_file_store_id';

    /**
     * Create the destination resolver with system setting access.
     */
    public function __construct(private readonly SettingServiceInterface $settings) {}

    /**
     * Return a valid saved destination, otherwise the seeded local fallback.
     */
    public function current(): FileStore
    {
        $id = $this->settings->get(self::SettingKey);

        if (is_string($id) && $id !== '') {
            $fileStore = FileStore::query()->find($id);

            if ($fileStore !== null && $this->isEligibleDestination($fileStore)) {
                return $fileStore;
            }
        }

        return $this->localMediaStore();
    }

    /**
     * Persist a readable destination after rejecting write-only email stores.
     */
    public function set(FileStore $fileStore): void
    {
        if (! $this->isEligibleDestination($fileStore)) {
            throw new RuntimeException('Only Local storage media or a remote file store can be selected for media archives.');
        }

        $this->settings->set(self::SettingKey, $fileStore->id);
    }

    /**
     * Return the idempotent local media destination used as a safe fallback.
     */
    private function localMediaStore(): FileStore
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

        return $fileStore;
    }

    /**
     * Allow the managed local media area and remote destinations, but never a
     * local backup directory which has different retention and access rules.
     */
    private function isEligibleDestination(FileStore $fileStore): bool
    {
        return $fileStore->provider !== 'email'
            && ($fileStore->provider !== 'local'
                || ($fileStore->settings['root'] ?? null) === config('media-storage.store_root'));
    }
}
