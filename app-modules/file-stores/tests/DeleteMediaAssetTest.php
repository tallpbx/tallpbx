<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Support\Facades\File;
use Modules\CallRecordings\Models\CallRecording;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Jobs\DeleteMediaAsset;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\FileStoreServiceInterface;

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/media-delete');
    config()->set('media-storage.spool_root', $this->root.'/spool');
    File::ensureDirectoryExists(config('media-storage.spool_root'));
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
    File::deleteDirectory($this->root);
});

it('idempotently removes a remote object and its service-owned spool', function (): void {
    $owner = CallRecording::factory()->create(['tenant_id' => $this->tenant->id]);
    $store = FileStore::query()->create([
        'name' => 'Delete destination',
        'provider' => 'local',
        'settings' => ['root' => $this->root.'/destination'],
    ]);
    $spool = config('media-storage.spool_root').'/asset.wav';
    File::put($spool, 'spooled media');
    $asset = MediaAsset::query()->create([
        'tenant_id' => $this->tenant->id,
        'file_store_id' => $store->id,
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => (string) $owner->id,
        'category' => MediaCategory::CallRecording,
        'status' => MediaAssetStatus::Deleting,
        'object_key' => 'archive/delete.wav',
        'staging_path' => $spool,
        'original_filename' => 'delete.wav',
        'mime_type' => 'audio/wav',
        'byte_size' => filesize($spool),
        'sha256' => hash_file('sha256', $spool),
    ]);

    (new DeleteMediaAsset($asset->id))->handle(app(FileStoreServiceInterface::class));
    (new DeleteMediaAsset($asset->id))->handle(app(FileStoreServiceInterface::class));

    $asset->refresh();
    expect($asset->status)->toBe(MediaAssetStatus::Missing)
        ->and($asset->staging_path)->toBeNull()
        ->and(is_file($spool))->toBeFalse();
});
