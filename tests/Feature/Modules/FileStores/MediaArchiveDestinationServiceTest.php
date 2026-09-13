<?php

declare(strict_types=1);

use App\Services\SettingServiceInterface;
use Database\Seeders\LocalMediaFileStoreSeeder;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Services\MediaArchiveDestinationServiceInterface;

beforeEach(function (): void {
    config()->set('media-storage.store_root', storage_path('framework/testing/media-storage/store'));
});

it('seeds the local storage media destination idempotently and uses it by default', function (): void {
    app(LocalMediaFileStoreSeeder::class)->run();
    app(LocalMediaFileStoreSeeder::class)->run();

    /** @var MediaArchiveDestinationServiceInterface $service */
    $service = app(MediaArchiveDestinationServiceInterface::class);

    expect(FileStore::query()->where('name', 'Local storage - media')->count())->toBe(1)
        ->and($service->current()->name)->toBe('Local storage - media')
        ->and($service->current()->settings['root'])->toBe(config('media-storage.store_root'));
});

it('allows any readable non-email store to become the archive destination', function (): void {
    app(LocalMediaFileStoreSeeder::class)->run();

    $remoteStore = FileStore::query()->create([
        'name' => 'Remote archive',
        'provider' => 's3',
        'settings' => [
            'bucket' => 'tallpbx-media',
            'region' => 'us-west-2',
            'access_key' => 'key',
            'secret' => 'secret',
        ],
    ]);

    /** @var MediaArchiveDestinationServiceInterface $service */
    $service = app(MediaArchiveDestinationServiceInterface::class);
    $service->set($remoteStore);

    expect($service->current()->is($remoteStore))->toBeTrue()
        ->and(app(SettingServiceInterface::class)->get('media.archive_file_store_id'))->toBe($remoteStore->id);
});

it('rejects email and local backup stores and falls back to local storage media for invalid saved selections', function (): void {
    app(LocalMediaFileStoreSeeder::class)->run();

    $emailStore = FileStore::query()->create([
        'name' => 'Email archive',
        'provider' => 'email',
        'settings' => ['recipient' => 'archives@example.test'],
    ]);
    $localBackupStore = FileStore::query()->create([
        'name' => 'Another local backup directory',
        'provider' => 'local',
        'settings' => ['root' => storage_path('framework/testing/local-backups')],
    ]);

    /** @var MediaArchiveDestinationServiceInterface $service */
    $service = app(MediaArchiveDestinationServiceInterface::class);

    expect(fn () => $service->set($emailStore))->toThrow(RuntimeException::class);
    expect(fn () => $service->set($localBackupStore))->toThrow(RuntimeException::class);

    app(SettingServiceInterface::class)->set('media.archive_file_store_id', $emailStore->id);

    expect($service->current()->name)->toBe('Local storage - media');
});
