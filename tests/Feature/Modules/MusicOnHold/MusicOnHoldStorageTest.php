<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Database\Seeders\LocalMediaFileStoreSeeder;
use Illuminate\Support\Facades\File;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\MusicOnHold\Models\MusicOnHold;

beforeEach(function (): void {
    $this->mediaRoot = storage_path('framework/testing/moh-media-storage');
    config()->set('media-storage.store_root', $this->mediaRoot.'/store');
    app(LocalMediaFileStoreSeeder::class)->run();
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
    File::deleteDirectory($this->mediaRoot);
});

it('stores music on hold uploads in the local managed store', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'moh-audio-');
    file_put_contents($source, 'music on hold');
    $owner = MusicOnHold::factory()->create(['tenant_id' => $this->tenant->id]);

    $asset = app(MediaStorageServiceInterface::class)->storeLocal($owner, MediaCategory::MusicOnHold, $source, 'hold.wav', 'audio/wav');

    expect($asset->category)->toBe(MediaCategory::MusicOnHold)
        ->and($owner->mediaAsset->id)->toBe($asset->id)
        ->and(app(MediaStorageServiceInterface::class)->resolveLocalPath($asset->id))->toBeFile();

    unlink($source);
});
