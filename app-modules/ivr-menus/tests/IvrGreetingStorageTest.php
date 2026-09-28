<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Database\Seeders\LocalMediaFileStoreSeeder;
use Illuminate\Support\Facades\File;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\IvrMenus\Models\IvrMenu;

beforeEach(function (): void {
    $this->mediaRoot = storage_path('framework/testing/ivr-greeting-storage');
    config()->set('media-storage.store_root', $this->mediaRoot.'/store');
    app(LocalMediaFileStoreSeeder::class)->run();
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
    File::deleteDirectory($this->mediaRoot);
});

it('stores IVR greetings in the local managed store', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'ivr-greeting-');
    file_put_contents($source, 'ivr greeting');
    $owner = IvrMenu::factory()->create(['tenant_id' => $this->tenant->id]);

    $asset = app(MediaStorageServiceInterface::class)->storeLocal($owner, MediaCategory::IvrGreeting, $source, 'greeting.wav', 'audio/wav');

    expect($asset->category)->toBe(MediaCategory::IvrGreeting)
        ->and($owner->mediaAsset->id)->toBe($asset->id)
        ->and(app(MediaStorageServiceInterface::class)->resolveLocalPath($asset->id))->toBeFile();

    unlink($source);
});
