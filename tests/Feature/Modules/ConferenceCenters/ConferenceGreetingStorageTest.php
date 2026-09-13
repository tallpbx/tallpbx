<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Database\Seeders\LocalMediaFileStoreSeeder;
use Illuminate\Support\Facades\File;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;

beforeEach(function (): void {
    $this->mediaRoot = storage_path('framework/testing/conference-greeting-storage');
    config()->set('media-storage.store_root', $this->mediaRoot.'/store');
    app(LocalMediaFileStoreSeeder::class)->run();
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
    File::deleteDirectory($this->mediaRoot);
});

it('stores conference greetings in the local managed store', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'conference-greeting-');
    file_put_contents($source, 'conference greeting');
    $owner = ConferenceCenter::factory()->create(['tenant_id' => $this->tenant->id]);

    $asset = app(MediaStorageServiceInterface::class)->storeLocal($owner, MediaCategory::ConferenceGreeting, $source, 'greeting.wav', 'audio/wav');

    expect($asset->category)->toBe(MediaCategory::ConferenceGreeting)
        ->and($owner->mediaAsset->id)->toBe($asset->id)
        ->and(app(MediaStorageServiceInterface::class)->resolveLocalPath($asset->id))->toBeFile();

    unlink($source);
});
