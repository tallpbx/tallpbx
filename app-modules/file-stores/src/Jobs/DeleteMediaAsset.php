<?php

declare(strict_types=1);

namespace Modules\FileStores\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\FileStoreServiceInterface;

/**
 * Removes one archived media object and its service-owned local spool.
 */
class DeleteMediaAsset implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600, 14400, 43200, 86400];

    public int $uniqueFor = 172800;

    /**
     * Queue deletion for one immutable media asset UUID.
     */
    public function __construct(public readonly string $mediaAssetId) {}

    /**
     * Keep duplicate deletion requests keyed to the asset UUID.
     */
    public function uniqueId(): string
    {
        return $this->mediaAssetId;
    }

    /**
     * Exclude transfer work while deletion owns this asset.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('media-asset:'.$this->mediaAssetId))
            ->shared()
            ->releaseAfter(60)
            ->expireAfter(3600)];
    }

    /**
     * Delete remote objects idempotently and retain no service-owned spool.
     */
    public function handle(FileStoreServiceInterface $fileStores): void
    {
        $asset = MediaAsset::withoutGlobalScope('tenant')->with('fileStore')->findOrFail($this->mediaAssetId);

        if ($asset->status === MediaAssetStatus::Missing) {
            return;
        }

        $asset->update(['status' => MediaAssetStatus::Deleting]);

        if ($fileStores->fileExists($asset->fileStore, $asset->object_key)) {
            $fileStores->deleteFile($asset->fileStore, $asset->object_key);
        }

        if ($asset->staging_path !== null
            && $this->isServiceOwnedSpool($asset->staging_path)
            && is_file($asset->staging_path)) {
            unlink($asset->staging_path);
        }

        $asset->update(['status' => MediaAssetStatus::Missing, 'staging_path' => null]);
    }

    /**
     * Permit unlinking only files below the configured private spool root.
     */
    private function isServiceOwnedSpool(string $path): bool
    {
        $root = realpath((string) config('media-storage.spool_root'));
        $resolvedPath = realpath($path);

        return $root !== false
            && $resolvedPath !== false
            && str_starts_with($resolvedPath, $root.DIRECTORY_SEPARATOR);
    }
}
