<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Models\MediaAsset;

/**
 * Owns managed local media placement and optional completed-media archival.
 */
interface MediaStorageServiceInterface
{
    /**
     * Copy a runtime-only media source into managed local storage.
     */
    public function storeLocal(Model $owner, MediaCategory $category, string $sourcePath, string $originalFilename, string $mimeType): MediaAsset;

    /**
     * Register an existing FreeSWITCH file beneath the managed local root.
     */
    public function registerLocal(Model $owner, MediaCategory $category, string $absolutePath, string $originalFilename, string $mimeType): MediaAsset;

    /**
     * Archive a completed recording or fax to the configured destination.
     */
    public function archiveCompleted(Model $owner, MediaCategory $category, string $sourcePath, string $originalFilename, string $mimeType): MediaAsset;

    /**
     * Open a readable stream for an available media asset.
     *
     * @return resource
     */
    public function openReadStream(string $mediaAssetId);

    /**
     * Return the available local object path, or null for a remote archive.
     */
    public function resolveLocalPath(string $mediaAssetId): ?string;

    /**
     * Synchronize a pending remote archive. Task 4 supplies remote transfer.
     */
    public function synchronize(string $mediaAssetId): void;

    /** Requeue a retained failed remote archive after validating that its spool remains available. */
    public function retryArchive(string $mediaAssetId): void;

    /**
     * Request deletion and remove immediately when the asset is local.
     */
    public function requestDeletion(string $mediaAssetId): void;

    /**
     * Yield retained local objects and durable remote spools for backup input.
     *
     * @return iterable<string, string>
     */
    public function localBackupEntries(): iterable;
}
