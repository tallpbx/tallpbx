<?php

declare(strict_types=1);

namespace Modules\FileStores\Jobs;

use App\Models\Admin;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Notifications\MediaArchiveTransferFailed;
use Modules\FileStores\Services\FileStoreServiceInterface;
use RuntimeException;
use Throwable;

/**
 * Transfers one durable media spool and verifies the destination read-back.
 */
class SyncMediaArchive implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600, 14400, 43200, 86400];

    public int $uniqueFor = 172800;

    /**
     * Queue synchronization for one media asset UUID.
     */
    public function __construct(public readonly string $mediaAssetId) {}

    /**
     * Keep all duplicate transfer attempts keyed to the immutable asset UUID.
     */
    public function uniqueId(): string
    {
        return $this->mediaAssetId;
    }

    /**
     * Exclude deletion and duplicate transfer work for this asset.
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
     * Preserve the spool and alert each enabled administrator once on exhaustion.
     */
    public function failed(?Throwable $exception): void
    {
        $asset = MediaAsset::withoutGlobalScope('tenant')->find($this->mediaAssetId);

        if ($asset === null) {
            return;
        }

        if (in_array($asset->status, [MediaAssetStatus::Deleting, MediaAssetStatus::Missing], true)) {
            return;
        }

        $asset->update([
            'status' => MediaAssetStatus::Failed,
            'last_error' => mb_substr($exception?->getMessage() ?? 'Media archive transfer failed.', 0, 1000),
        ]);

        if ($asset->alerted_at !== null) {
            return;
        }

        $asset->update(['alerted_at' => now()]);
        Admin::query()->where('enabled', true)->each(
            fn (Admin $admin): mixed => $admin->notify(new MediaArchiveTransferFailed($asset)),
        );
    }

    /**
     * Copy the spool, reopen the destination, and retain the spool until verified.
     */
    public function handle(FileStoreServiceInterface $fileStores): void
    {
        $asset = MediaAsset::withoutGlobalScope('tenant')->with('fileStore')->findOrFail($this->mediaAssetId);

        if (! in_array($asset->status, [MediaAssetStatus::Pending, MediaAssetStatus::Failed, MediaAssetStatus::Transferring], true)) {
            return;
        }

        if ($asset->staging_path === null || ! is_file($asset->staging_path)) {
            throw new RuntimeException('Media archive spool is unavailable.');
        }

        $spoolPath = $asset->staging_path;

        $asset->update([
            'status' => MediaAssetStatus::Transferring,
            'sync_attempts' => $asset->sync_attempts + 1,
            'last_attempt_at' => now(),
        ]);

        $source = fopen($asset->staging_path, 'rb');

        if ($source === false) {
            throw new RuntimeException('Media archive spool could not be opened.');
        }

        try {
            $fileStores->writeStream($asset->fileStore, $asset->object_key, $source);
        } finally {
            fclose($source);
        }

        $stream = $fileStores->readStream($asset->fileStore, $asset->object_key);
        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    throw new RuntimeException('Media archive destination could not be read.');
                }

                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        if ($bytes !== $asset->byte_size || hash_final($hash) !== $asset->sha256) {
            throw new RuntimeException('Media archive destination verification failed.');
        }

        if (! unlink($spoolPath)) {
            throw new RuntimeException('Verified media archive spool could not be removed.');
        }

        $asset->update([
            'status' => MediaAssetStatus::Available,
            'staging_path' => null,
            'synced_at' => now(),
            'last_error' => null,
            'alerted_at' => null,
        ]);
    }
}
