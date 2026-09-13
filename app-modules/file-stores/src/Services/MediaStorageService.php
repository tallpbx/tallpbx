<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Jobs\DeleteMediaAsset;
use Modules\FileStores\Jobs\SyncMediaArchive;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use RuntimeException;

/**
 * Keeps TallPBX-managed media local first and records its archive lifecycle.
 */
class MediaStorageService implements MediaStorageServiceInterface
{
    /**
     * Categories that may be archived after their owning workflow completes.
     *
     * @var list<MediaCategory>
     */
    private const ArchiveCategories = [
        MediaCategory::CallRecording,
        MediaCategory::FaxInbound,
        MediaCategory::FaxOutbound,
    ];

    /**
     * Create the service with destination and provider stream access.
     */
    public function __construct(
        private readonly MediaArchiveDestinationServiceInterface $archiveDestination,
        private readonly FileStoreServiceInterface $fileStores,
    ) {}

    /**
     * Copy runtime-only media to the local store using an atomic replacement.
     */
    public function storeLocal(Model $owner, MediaCategory $category, string $sourcePath, string $originalFilename, string $mimeType): MediaAsset
    {
        if (in_array($category, [MediaCategory::CallRecording, MediaCategory::FaxInbound], true)) {
            throw new RuntimeException('Completed archival media must use archiveCompleted.');
        }

        return $this->storeAtLocalDestination(
            $owner,
            $category,
            $sourcePath,
            $originalFilename,
            $mimeType,
            'runtime',
        );
    }

    /**
     * Register a FreeSWITCH-created local object after confirming its boundary.
     */
    public function registerLocal(Model $owner, MediaCategory $category, string $absolutePath, string $originalFilename, string $mimeType): MediaAsset
    {
        $this->assertOwnerCanBeReplaced($owner);
        $localStore = $this->localMediaStore();
        $root = $this->canonicalRoot((string) $localStore->settings['root']);
        $path = realpath($absolutePath);

        if ($path === false || ! $this->isWithinRoot($path, $root) || ! is_file($path)) {
            throw new RuntimeException('Registered media must be a file below the managed local media root.');
        }

        $relativePath = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);

        return $this->persistAsset(
            $owner,
            $category,
            $localStore,
            $relativePath,
            null,
            $originalFilename,
            $mimeType,
            $path,
            MediaAssetStatus::Available,
        );
    }

    /**
     * Store a completed archive locally now or create a durable remote spool.
     */
    public function archiveCompleted(Model $owner, MediaCategory $category, string $sourcePath, string $originalFilename, string $mimeType): MediaAsset
    {
        if (! in_array($category, self::ArchiveCategories, true)) {
            throw new RuntimeException('This media category is not eligible for archival.');
        }

        $this->assertOwnerCanBeReplaced($owner);
        $destination = $this->archiveDestination->current();
        $objectKey = $this->archiveObjectKey($owner, $category, $sourcePath);

        if ($destination->provider === 'local') {
            $sourceMetadata = $this->fileMetadata($sourcePath);
            $destinationPath = $this->writeAtomically(
                $sourcePath,
                $this->localDirectory($destination, dirname($objectKey)),
                basename($objectKey),
            );
            $this->verifyStoredObject($destination, $objectKey, $sourceMetadata);

            return $this->persistAsset(
                $owner,
                $category,
                $destination,
                $objectKey,
                null,
                $originalFilename,
                $mimeType,
                $destinationPath,
                MediaAssetStatus::Available,
            );
        }

        $spoolPath = $this->writeAtomically(
            $sourcePath,
            $this->spoolDirectory($owner, $category),
            basename($objectKey),
        );

        $asset = $this->persistAsset(
            $owner,
            $category,
            $destination,
            $objectKey,
            $spoolPath,
            $originalFilename,
            $mimeType,
            $spoolPath,
            MediaAssetStatus::Pending,
        );

        SyncMediaArchive::dispatch($asset->id)->afterCommit();

        return $asset;
    }

    /**
     * Open an available asset through its configured File Store.
     *
     * @return resource
     */
    public function openReadStream(string $mediaAssetId)
    {
        $asset = $this->availableAsset($mediaAssetId);

        return $this->fileStores->readStream($asset->fileStore, $asset->object_key);
    }

    /**
     * Resolve only available assets held by a local File Store.
     */
    public function resolveLocalPath(string $mediaAssetId): ?string
    {
        $asset = $this->availableAsset($mediaAssetId);

        return $this->fileStores->resolveLocalPath($asset->fileStore, $asset->object_key);
    }

    /**
     * Reserve remote synchronization behavior for the Task 4 transfer worker.
     */
    public function synchronize(string $mediaAssetId): void
    {
        SyncMediaArchive::dispatch($mediaAssetId)->afterCommit();
    }

    /** Requeue a failed archive only when its durable local spool is still available. */
    public function retryArchive(string $mediaAssetId): void
    {
        $asset = MediaAsset::withoutGlobalScope('tenant')->findOrFail($mediaAssetId);

        if ($asset->status !== MediaAssetStatus::Failed) {
            throw new RuntimeException('Only failed media archives can be retried.');
        }

        if ($asset->staging_path === null || ! is_file($asset->staging_path)) {
            throw new RuntimeException('The retained media archive spool is no longer available for retry.');
        }

        $asset->update([
            'status' => MediaAssetStatus::Pending,
            'last_attempt_at' => null,
            'alerted_at' => null,
        ]);

        $this->synchronize($asset->id);
    }

    /**
     * Transition to deleting and immediately remove a local available object.
     */
    public function requestDeletion(string $mediaAssetId): void
    {
        $asset = MediaAsset::withoutGlobalScope('tenant')->findOrFail($mediaAssetId);
        $asset->update(['status' => MediaAssetStatus::Deleting]);

        if ($asset->fileStore->provider !== 'local') {
            DeleteMediaAsset::dispatch($asset->id)->afterCommit();

            return;
        }

        $this->fileStores->deleteFile($asset->fileStore, $asset->object_key);
        $asset->update(['status' => MediaAssetStatus::Missing]);
    }

    /**
     * Yield available local paths together with retained remote archive spools.
     *
     * @return iterable<string, string>
     */
    public function localBackupEntries(): iterable
    {
        foreach (MediaAsset::withoutGlobalScope('tenant')->cursor() as $asset) {
            // Pending, Failed, and mid-transfer spools are all durable local
            // copies: a backup taken while an archive job is in flight must
            // still carry the spool so the recording survives a host failure.
            if (in_array($asset->status, [MediaAssetStatus::Pending, MediaAssetStatus::Failed, MediaAssetStatus::Transferring], true)
                && $asset->staging_path !== null
                && is_file($asset->staging_path)) {
                yield $asset->id => $asset->staging_path;

                continue;
            }

            if ($asset->status === MediaAssetStatus::Available) {
                $path = $this->fileStores->resolveLocalPath($asset->fileStore, $asset->object_key);

                if ($path !== null) {
                    yield $asset->id => $path;
                }
            }
        }
    }

    /**
     * Write local-only media beneath its dedicated runtime directory.
     */
    private function storeAtLocalDestination(Model $owner, MediaCategory $category, string $sourcePath, string $originalFilename, string $mimeType, string $area): MediaAsset
    {
        $this->assertOwnerCanBeReplaced($owner);
        $fileStore = $this->localMediaStore();
        $objectKey = sprintf(
            '%s/%s/%s/%s',
            $area,
            $this->tenantId($owner),
            $category->value,
            $this->generatedFilename($sourcePath),
        );
        $destinationPath = $this->writeAtomically(
            $sourcePath,
            $this->localDirectory($fileStore, dirname($objectKey)),
            basename($objectKey),
        );

        return $this->persistAsset(
            $owner,
            $category,
            $fileStore,
            $objectKey,
            null,
            $originalFilename,
            $mimeType,
            $destinationPath,
            MediaAssetStatus::Available,
        );
    }

    /**
     * Persist the replacement after its new object is fully written and hashed.
     */
    private function persistAsset(Model $owner, MediaCategory $category, FileStore $fileStore, string $objectKey, ?string $stagingPath, string $originalFilename, string $mimeType, string $physicalPath, MediaAssetStatus $status): MediaAsset
    {
        $ownerType = $owner->getMorphClass();
        $previous = MediaAsset::withoutGlobalScope('tenant')
            ->where('owner_type', $ownerType)
            ->where('owner_id', (string) $owner->getKey())
            ->first();
        $oldFileStore = $previous?->fileStore;
        $oldObjectKey = $previous?->object_key;

        $asset = DB::transaction(function () use ($owner, $category, $fileStore, $objectKey, $stagingPath, $originalFilename, $mimeType, $physicalPath, $status, $previous): MediaAsset {
            $attributes = [
                'tenant_id' => $this->tenantId($owner),
                'file_store_id' => $fileStore->id,
                'category' => $category,
                'status' => $status,
                'object_key' => $objectKey,
                'staging_path' => $stagingPath,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'byte_size' => filesize($physicalPath),
                'sha256' => hash_file('sha256', $physicalPath),
                'synced_at' => $status === MediaAssetStatus::Available ? now() : null,
                'last_error' => null,
            ];

            if ($previous === null) {
                return MediaAsset::withoutGlobalScope('tenant')->create([
                    ...$attributes,
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => (string) $owner->getKey(),
                ]);
            }

            $previous->update($attributes);

            return $previous->fresh();
        });

        if ($oldFileStore !== null
            && $oldFileStore->provider === 'local'
            && $oldObjectKey !== null
            && ($oldFileStore->id !== $fileStore->id || $oldObjectKey !== $objectKey)) {
            $this->fileStores->deleteFile($oldFileStore, $oldObjectKey);
        }

        return $asset;
    }

    /**
     * Return the asset only when it is safe to make its contents available.
     */
    private function availableAsset(string $mediaAssetId): MediaAsset
    {
        $asset = MediaAsset::withoutGlobalScope('tenant')->with('fileStore')->findOrFail($mediaAssetId);

        if ($asset->status !== MediaAssetStatus::Available) {
            throw new RuntimeException('The requested media asset is not available.');
        }

        return $asset;
    }

    /**
     * Preserve a remote object's sole durable reference until Task 4 owns it.
     */
    private function assertOwnerCanBeReplaced(Model $owner): void
    {
        $existing = MediaAsset::withoutGlobalScope('tenant')
            ->with('fileStore')
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', (string) $owner->getKey())
            ->first();

        if ($existing !== null && $existing->fileStore->provider !== 'local') {
            throw new RuntimeException('Remote media assets cannot be replaced until archive reconciliation is available.');
        }
    }

    /**
     * Return the dedicated local media File Store.
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
     * Build a provider-relative archive object key without trusting filenames.
     */
    private function archiveObjectKey(Model $owner, MediaCategory $category, string $sourcePath): string
    {
        return sprintf(
            'archive/%s/%s/%s/%s',
            $this->tenantId($owner),
            $category->value,
            now()->format('Y/m'),
            $this->generatedFilename($sourcePath),
        );
    }

    /**
     * Build the private durable spool directory for a remote archive source.
     */
    private function spoolDirectory(Model $owner, MediaCategory $category): string
    {
        return rtrim((string) config('media-storage.spool_root'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$this->tenantId($owner)
            .DIRECTORY_SEPARATOR.$category->value
            .DIRECTORY_SEPARATOR.now()->format('Y')
            .DIRECTORY_SEPARATOR.now()->format('m');
    }

    /**
     * Create and validate a directory beneath a local File Store root.
     */
    private function localDirectory(FileStore $fileStore, string $relativeDirectory): string
    {
        $root = $this->canonicalRoot((string) $fileStore->settings['root']);
        $directory = $root.DIRECTORY_SEPARATOR.trim($relativeDirectory, DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($directory);
        $canonicalDirectory = realpath($directory);

        if ($canonicalDirectory === false || ! $this->isWithinRoot($canonicalDirectory, $root)) {
            throw new RuntimeException('Managed media destination escapes the local store root.');
        }

        return $canonicalDirectory;
    }

    /**
     * Copy a source through a same-directory temporary file before replacing it.
     */
    private function writeAtomically(string $sourcePath, string $directory, string $filename): string
    {
        $source = realpath($sourcePath);

        if ($source === false || ! is_file($source)) {
            throw new RuntimeException('Media source file does not exist.');
        }

        File::ensureDirectoryExists($directory);
        $destination = $directory.DIRECTORY_SEPARATOR.$filename;
        $temporary = $directory.DIRECTORY_SEPARATOR.'.'.$filename.'.'.Str::uuid().'.tmp';

        if (! copy($source, $temporary)) {
            throw new RuntimeException('Media source could not be copied to managed storage.');
        }

        if (! rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Managed media file could not be finalized.');
        }

        return $destination;
    }

    /**
     * Return byte and hash metadata for a source before it is archived.
     *
     * @return array{bytes: int, sha256: string}
     */
    private function fileMetadata(string $path): array
    {
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);

        if ($bytes === false || $sha256 === false) {
            throw new RuntimeException('Media source metadata could not be calculated.');
        }

        return ['bytes' => $bytes, 'sha256' => $sha256];
    }

    /**
     * Reopen a completed local archive and compare it with its source metadata.
     *
     * @param  array{bytes: int, sha256: string}  $expected
     */
    private function verifyStoredObject(FileStore $fileStore, string $objectKey, array $expected): void
    {
        $stream = $this->fileStores->readStream($fileStore, $objectKey);
        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    throw new RuntimeException('Stored media archive could not be read back.');
                }

                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        if ($bytes !== $expected['bytes'] || hash_final($hash) !== $expected['sha256']) {
            throw new RuntimeException('Stored media archive did not match its source.');
        }
    }

    /**
     * Create a generated object name that preserves only a safe extension.
     */
    private function generatedFilename(string $sourcePath): string
    {
        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';

        return Str::uuid().'.'.$extension;
    }

    /**
     * Return a canonical local root, creating it when the service owns it.
     */
    private function canonicalRoot(string $root): string
    {
        File::ensureDirectoryExists($root);
        $canonicalRoot = realpath($root);

        if ($canonicalRoot === false) {
            throw new RuntimeException('Managed media root could not be resolved.');
        }

        return $canonicalRoot;
    }

    /**
     * Check that a canonical path remains strictly within a canonical root.
     */
    private function isWithinRoot(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * Read the required tenant identifier from a media owner.
     */
    private function tenantId(Model $owner): int
    {
        $tenantId = $owner->getAttribute('tenant_id');

        if (! is_int($tenantId) && ! ctype_digit((string) $tenantId)) {
            throw new RuntimeException('Media owners must belong to a tenant.');
        }

        return (int) $tenantId;
    }
}
