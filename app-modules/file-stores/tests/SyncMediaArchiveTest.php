<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Modules\CallRecordings\Models\CallRecording;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Jobs\SyncMediaArchive;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Notifications\MediaArchiveTransferFailed;
use Modules\FileStores\Services\FileStoreServiceInterface;

beforeEach(function (): void {
    app(TenantManager::class)->clear();
    $this->root = storage_path('framework/testing/media-sync');
    File::ensureDirectoryExists($this->root);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
    File::deleteDirectory($this->root);
});

it('defines a UUID-only unique job with the required retry policy', function (): void {
    $job = new SyncMediaArchive('asset-uuid');

    expect($job->uniqueId())->toBe('asset-uuid')
        ->and($job->tries)->toBe(8)
        ->and($job->backoff)->toBe([60, 300, 900, 3600, 14400, 43200, 86400]);
});

it('synchronizes a pending spool only after destination read-back matches its hash', function (): void {
    $owner = CallRecording::factory()->create(['tenant_id' => $this->tenant->id]);
    $store = FileStore::query()->create([
        'name' => 'Sync destination',
        'provider' => 'local',
        'settings' => ['root' => $this->root.'/destination'],
    ]);
    $spool = $this->root.'/spool.wav';
    File::put($spool, 'verified media');
    $asset = MediaAsset::query()->create([
        'tenant_id' => $this->tenant->id,
        'file_store_id' => $store->id,
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => (string) $owner->id,
        'category' => MediaCategory::CallRecording,
        'status' => MediaAssetStatus::Pending,
        'object_key' => 'archive/verified.wav',
        'staging_path' => $spool,
        'original_filename' => 'verified.wav',
        'mime_type' => 'audio/wav',
        'byte_size' => filesize($spool),
        'sha256' => hash_file('sha256', $spool),
    ]);

    (new SyncMediaArchive($asset->id))->handle(app(FileStoreServiceInterface::class));

    $asset->refresh();
    expect($asset->status)->toBe(MediaAssetStatus::Available)
        ->and($asset->staging_path)->toBeNull()
        ->and(is_file($spool))->toBeFalse();
});

it('continues a previously started transfer when the queue retries it', function (): void {
    $owner = CallRecording::factory()->create(['tenant_id' => $this->tenant->id]);
    $store = FileStore::query()->create([
        'name' => 'Retry destination',
        'provider' => 'local',
        'settings' => ['root' => $this->root.'/destination'],
    ]);
    $spool = $this->root.'/retry.wav';
    File::put($spool, 'retry media');
    $asset = MediaAsset::query()->create([
        'tenant_id' => $this->tenant->id,
        'file_store_id' => $store->id,
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => (string) $owner->id,
        'category' => MediaCategory::CallRecording,
        'status' => MediaAssetStatus::Transferring,
        'object_key' => 'archive/retry.wav',
        'staging_path' => $spool,
        'original_filename' => 'retry.wav',
        'mime_type' => 'audio/wav',
        'byte_size' => filesize($spool),
        'sha256' => hash_file('sha256', $spool),
    ]);

    (new SyncMediaArchive($asset->id))->handle(app(FileStoreServiceInterface::class));

    $asset->refresh();
    expect($asset->status)->toBe(MediaAssetStatus::Available)
        ->and($asset->staging_path)->toBeNull()
        ->and(is_file($spool))->toBeFalse();
});

it('retains the spool and notifies enabled admins once after exhausted transfer failure', function (): void {
    Notification::fake();
    $admin = Admin::factory()->create(['enabled' => true]);
    $owner = CallRecording::factory()->create(['tenant_id' => $this->tenant->id]);
    $store = FileStore::query()->create([
        'name' => 'Failure destination',
        'provider' => 'local',
        'settings' => ['root' => $this->root.'/destination'],
    ]);
    $spool = $this->root.'/retained.wav';
    File::put($spool, 'retained media');
    $asset = MediaAsset::query()->create([
        'tenant_id' => $this->tenant->id,
        'file_store_id' => $store->id,
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => (string) $owner->id,
        'category' => MediaCategory::CallRecording,
        'status' => MediaAssetStatus::Transferring,
        'object_key' => 'archive/retained.wav',
        'staging_path' => $spool,
        'original_filename' => 'retained.wav',
        'mime_type' => 'audio/wav',
        'byte_size' => filesize($spool),
        'sha256' => hash_file('sha256', $spool),
    ]);

    $job = new SyncMediaArchive($asset->id);
    $job->failed(new RuntimeException('transfer failed'));
    $job->failed(new RuntimeException('transfer failed again'));

    $asset->refresh();
    expect($asset->status)->toBe(MediaAssetStatus::Failed)
        ->and($asset->alerted_at)->not->toBeNull()
        ->and(is_file($spool))->toBeTrue();
    Notification::assertSentTo($admin, MediaArchiveTransferFailed::class, 1);
});

it('includes only safe media details in an archive failure notification', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);
    $owner = CallRecording::factory()->create(['tenant_id' => $this->tenant->id]);
    $store = FileStore::query()->create([
        'name' => 'Notification destination',
        'provider' => 'local',
        'settings' => ['root' => $this->root.'/destination'],
    ]);
    $asset = MediaAsset::query()->create([
        'tenant_id' => $this->tenant->id,
        'file_store_id' => $store->id,
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => (string) $owner->id,
        'category' => MediaCategory::CallRecording,
        'status' => MediaAssetStatus::Failed,
        'object_key' => 'archive/private-object.wav',
        'staging_path' => $this->root.'/private-spool.wav',
        'original_filename' => 'customer-call.wav',
        'mime_type' => 'audio/wav',
        'byte_size' => 12,
        'sha256' => hash('sha256', 'fixture data'),
    ]);

    $payload = (new MediaArchiveTransferFailed($asset))->toArray($admin);

    expect($payload)
        ->toMatchArray([
            'category' => MediaCategory::CallRecording->value,
            'original_filename' => 'customer-call.wav',
        ])
        ->not->toHaveKeys(['staging_path', 'object_key', 'last_error']);
});
