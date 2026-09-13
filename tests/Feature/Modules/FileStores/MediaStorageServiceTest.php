<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Database\Seeders\LocalMediaFileStoreSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Modules\CallRecordings\Models\CallRecording;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\MediaArchiveDestinationServiceInterface;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\Recordings\Models\Recording;

beforeEach(function (): void {
    $this->mediaRoot = storage_path('framework/testing/media-storage');
    config()->set('media-storage.store_root', $this->mediaRoot.'/store');
    config()->set('media-storage.spool_root', $this->mediaRoot.'/spool');
    app(TenantManager::class)->clear();
    app(LocalMediaFileStoreSeeder::class)->run();

    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
    File::deleteDirectory($this->mediaRoot);
});

it('atomically stores local-only media and safely replaces its prior object', function (): void {
    $owner = Recording::factory()->create(['tenant_id' => $this->tenant->id]);
    $firstSource = sourceFile('first.wav', 'first recording');

    /** @var MediaStorageServiceInterface $service */
    $service = app(MediaStorageServiceInterface::class);
    $first = $service->storeLocal($owner, MediaCategory::Recording, $firstSource, 'first.wav', 'audio/wav');
    $firstPath = $service->resolveLocalPath($first->id);

    expect($first->status)->toBe(MediaAssetStatus::Available)
        ->and($firstPath)->toBeString()
        ->and(file_get_contents($firstPath))->toBe('first recording');

    $replacement = $service->storeLocal(
        $owner,
        MediaCategory::Recording,
        sourceFile('replacement.wav', 'replacement recording'),
        'replacement.wav',
        'audio/wav',
    );

    expect($replacement->id)->toBe($first->id)
        ->and($service->resolveLocalPath($replacement->id))->not->toBe($firstPath)
        ->and(is_file($firstPath))->toBeFalse();
});

it('keeps an eligible remote archive in a durable local spool until task four synchronizes it', function (): void {
    Queue::fake();
    $owner = CallRecording::factory()->create(['tenant_id' => $this->tenant->id]);
    $remoteStore = FileStore::query()->create([
        'name' => 'Remote media archive',
        'provider' => 's3',
        'settings' => [
            'bucket' => 'tallpbx-media',
            'region' => 'us-west-2',
            'access_key' => 'key',
            'secret' => 'secret',
        ],
    ]);
    app(MediaArchiveDestinationServiceInterface::class)->set($remoteStore);

    /** @var MediaStorageServiceInterface $service */
    $service = app(MediaStorageServiceInterface::class);
    $asset = $service->archiveCompleted(
        $owner,
        MediaCategory::CallRecording,
        sourceFile('completed.wav', 'completed recording'),
        'completed.wav',
        'audio/wav',
    );

    expect($asset->status)->toBe(MediaAssetStatus::Pending)
        ->and($asset->file_store_id)->toBe($remoteStore->id)
        ->and($asset->staging_path)->not->toBeNull()
        ->and(is_file($asset->staging_path))->toBeTrue()
        ->and(file_get_contents($asset->staging_path))->toBe('completed recording')
        ->and(fn (): ?string => $service->resolveLocalPath($asset->id))->toThrow(RuntimeException::class);
});

it('writes and verifies completed archives synchronously for a local destination', function (): void {
    $owner = CallRecording::factory()->create(['tenant_id' => $this->tenant->id]);

    /** @var MediaStorageServiceInterface $service */
    $service = app(MediaStorageServiceInterface::class);
    $asset = $service->archiveCompleted(
        $owner,
        MediaCategory::CallRecording,
        sourceFile('local-completed.wav', 'local completed recording'),
        'local-completed.wav',
        'audio/wav',
    );
    $path = $service->resolveLocalPath($asset->id);

    expect($asset->status)->toBe(MediaAssetStatus::Available)
        ->and($asset->staging_path)->toBeNull()
        ->and($path)->toBeString()
        ->and(file_get_contents($path))->toBe('local completed recording');
});

it('does not replace an existing remote archive before task four can reconcile its spool', function (): void {
    $owner = Recording::factory()->create(['tenant_id' => $this->tenant->id]);
    $emailStore = FileStore::query()->create([
        'name' => 'Prior remote archive',
        'provider' => 'email',
        'settings' => ['recipient' => 'archives@example.test'],
    ]);
    $stagingPath = sourceFile('prior-spool.wav', 'prior spool');
    $previous = MediaAsset::query()->create([
        'tenant_id' => $this->tenant->id,
        'file_store_id' => $emailStore->id,
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => (string) $owner->id,
        'category' => MediaCategory::CallRecording,
        'status' => MediaAssetStatus::Pending,
        'object_key' => 'archive/prior.wav',
        'staging_path' => $stagingPath,
        'original_filename' => 'prior.wav',
        'mime_type' => 'audio/wav',
        'byte_size' => filesize($stagingPath),
        'sha256' => hash_file('sha256', $stagingPath),
    ]);

    /** @var MediaStorageServiceInterface $service */
    $service = app(MediaStorageServiceInterface::class);
    expect(fn () => $service->storeLocal(
        $owner,
        MediaCategory::Recording,
        sourceFile('new-runtime.wav', 'new runtime'),
        'new-runtime.wav',
        'audio/wav',
    ))->toThrow(RuntimeException::class);

    expect(MediaAsset::query()->whereKey($previous->id)->value('staging_path'))->toBe($stagingPath)
        ->and(is_file($stagingPath))->toBeTrue();
});

it('includes pending, failed, and transferring remote spools in local backup entries', function (): void {
    $owner = CallRecording::factory()->create(['tenant_id' => $this->tenant->id]);
    $remoteStore = FileStore::query()->create([
        'name' => 'Remote backup filter',
        'provider' => 's3',
        'settings' => [
            'bucket' => 'tallpbx-media',
            'region' => 'us-west-2',
            'access_key' => 'key',
            'secret' => 'secret',
        ],
    ]);
    $spoolPath = sourceFile('transferring-spool.wav', 'transferring spool');
    $asset = MediaAsset::query()->create([
        'tenant_id' => $this->tenant->id,
        'file_store_id' => $remoteStore->id,
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => (string) $owner->id,
        'category' => MediaCategory::CallRecording,
        'status' => MediaAssetStatus::Transferring,
        'object_key' => 'archive/transferring.wav',
        'staging_path' => $spoolPath,
        'original_filename' => 'transferring.wav',
        'mime_type' => 'audio/wav',
        'byte_size' => filesize($spoolPath),
        'sha256' => hash_file('sha256', $spoolPath),
    ]);

    /** @var MediaStorageServiceInterface $service */
    $service = app(MediaStorageServiceInterface::class);

    // A mid-transfer spool is the durable copy of the recording: if the host
    // dies while the archive job is in flight, the backup must still carry it.
    expect(iterator_to_array($service->localBackupEntries()))->toHaveKey($asset->id);
});

it('rejects missing sources and categories that are ineligible for archival', function (): void {
    $owner = Recording::factory()->create(['tenant_id' => $this->tenant->id]);

    /** @var MediaStorageServiceInterface $service */
    $service = app(MediaStorageServiceInterface::class);

    expect(fn (): mixed => $service->storeLocal(
        $owner,
        MediaCategory::Recording,
        $this->mediaRoot.'/missing.wav',
        'missing.wav',
        'audio/wav',
    ))->toThrow(RuntimeException::class)
        ->and(fn (): mixed => $service->archiveCompleted(
            $owner,
            MediaCategory::Recording,
            sourceFile('runtime.wav', 'runtime'),
            'runtime.wav',
            'audio/wav',
        ))->toThrow(RuntimeException::class);
});

/**
 * Create a disposable source file outside the managed store and spool roots.
 */
function sourceFile(string $name, string $contents): string
{
    $directory = storage_path('framework/testing/media-storage/sources');
    File::ensureDirectoryExists($directory);
    $path = $directory.'/'.$name;
    File::put($path, $contents);

    return $path;
}
