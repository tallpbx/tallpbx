<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Backups\Models\Backup;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Services\EmailFileStoreAdapter;
use Modules\FileStores\Services\FileStoreAdapterFactoryInterface;
use Modules\FileStores\Services\FileStoreServiceInterface;
use Modules\FileStores\Services\FlysystemFileStoreAdapter;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->storageRoot = storage_path('framework/testing/file-stores');
});

afterEach(function (): void {
    if (is_dir($this->storageRoot)) {
        app('files')->deleteDirectory($this->storageRoot);
    }
});

it('encrypts destination settings and supports local stream operations', function (): void {
    /** @var FileStoreServiceInterface $service */
    $service = app(FileStoreServiceInterface::class);

    $fileStore = $service->create([
        'name' => 'Local archives',
        'provider' => 'local',
        'settings' => [
            'root' => $this->storageRoot,
            'access_key' => 'not-for-serialization',
        ],
    ]);

    $rawSettings = DB::table('file_stores')->where('id', $fileStore->id)->value('settings');

    expect($fileStore)->toBeInstanceOf(FileStore::class)
        ->and($fileStore->settings)->toMatchArray(['root' => $this->storageRoot])
        ->and($fileStore->toArray())->not->toHaveKey('settings')
        ->and($rawSettings)->not->toContain('not-for-serialization');

    $service->testConnection($fileStore);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'TallPBX archive');
    rewind($stream);
    $service->writeStream($fileStore, 'daily/archive.txt', $stream);
    fclose($stream);

    expect($service->listFiles($fileStore, 'daily'))->toBe(['daily/archive.txt'])
        ->and($service->fileExists($fileStore, 'daily/archive.txt'))->toBeTrue()
        ->and($service->resolveLocalPath($fileStore, 'daily/archive.txt'))->toBe($this->storageRoot.'/daily/archive.txt');

    $readStream = $service->readStream($fileStore, 'daily/archive.txt');

    expect(stream_get_contents($readStream))->toBe('TallPBX archive');
    fclose($readStream);

    $service->deleteFile($fileStore, 'daily/archive.txt');

    expect($service->listFiles($fileStore, 'daily'))->toBe([]);
});

it('rejects local path resolution through a symlink that escapes the store root', function (): void {
    /** @var FileStoreServiceInterface $service */
    $service = app(FileStoreServiceInterface::class);
    $outsidePath = storage_path('framework/testing/outside-media.txt');
    app('files')->put($outsidePath, 'outside');

    $fileStore = $service->create([
        'name' => 'Symlink safety store',
        'provider' => 'local',
        'settings' => ['root' => $this->storageRoot],
    ]);

    app('files')->ensureDirectoryExists($this->storageRoot);
    symlink($outsidePath, $this->storageRoot.'/escaped.txt');

    expect($service->resolveLocalPath($fileStore, 'escaped.txt'))->toBeNull();

    @unlink($outsidePath);
});

it('prevents deleting the selected media archive destination', function (): void {
    /** @var FileStoreServiceInterface $service */
    $service = app(FileStoreServiceInterface::class);
    $fileStore = $service->create([
        'name' => 'Selected media archive',
        'provider' => 'local',
        'settings' => ['root' => $this->storageRoot],
    ]);
    app(SettingServiceInterface::class)->set('media.archive_file_store_id', $fileStore->id);

    expect(fn () => $service->delete($fileStore))->toThrow(RuntimeException::class);
});

it('names the backups that prevent file store deletion', function (): void {
    $service = app(FileStoreServiceInterface::class);
    $fileStore = $service->create([
        'name' => 'Remote SFTP test',
        'provider' => 'local',
        'settings' => ['root' => $this->storageRoot],
    ]);
    Backup::factory()->create([
        'name' => 'Disaster recovery drill',
        'file_store_id' => $fileStore->id,
    ]);

    expect(fn () => $service->delete($fileStore))
        ->toThrow(RuntimeException::class, 'Cannot delete “Remote SFTP test” because it is used by backup “Disaster recovery drill”. Delete or move that backup first.');
});

it('rejects provider settings that cannot create a usable destination', function (): void {
    /** @var FileStoreServiceInterface $service */
    $service = app(FileStoreServiceInterface::class);

    expect(fn (): FileStore => $service->create([
        'name' => 'Incomplete S3',
        'provider' => 's3',
        'settings' => ['bucket' => 'tallpbx-backups'],
    ]))->toThrow(ValidationException::class);
});

it('builds supported remote adapters without making a network connection', function (): void {
    /** @var FileStoreServiceInterface $service */
    $service = app(FileStoreServiceInterface::class);

    $profiles = [
        ['s3', ['bucket' => 'tallpbx-backups', 'region' => 'us-west-2', 'access_key' => 'access-key', 'secret' => 'secret-key']],
        ['ftp', ['host' => 'ftp.example.test', 'username' => 'backup', 'password' => 'secret']],
        ['sftp', ['host' => 'sftp.example.test', 'username' => 'backup', 'password' => 'secret']],
        ['ssh', ['host' => 'ssh.example.test', 'username' => 'backup', 'password' => 'secret']],
        ['dropbox', ['access_token' => 'token']],
    ];

    /** @var FileStoreAdapterFactoryInterface $factory */
    $factory = app(FileStoreAdapterFactoryInterface::class);

    foreach ($profiles as [$provider, $settings]) {
        $fileStore = $service->create([
            'name' => "{$provider} archives",
            'provider' => $provider,
            'settings' => $settings,
        ]);

        expect($factory->make($fileStore))->toBeInstanceOf(FlysystemFileStoreAdapter::class);
    }

    $emailFileStore = $service->create([
        'name' => 'Email archives',
        'provider' => 'email',
        'settings' => ['recipient' => 'backups@example.test'],
    ]);

    expect($factory->make($emailFileStore))->toBeInstanceOf(EmailFileStoreAdapter::class);
});

it('denies tenant users access to file store management', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['enabled' => true]);
    $tenant->users()->attach($user, ['role' => 'admin']);

    actingAs($user)
        ->withSession(['selected_tenant_id' => (string) $tenant->id])
        ->get(route('panel.file-stores.index'))
        ->assertForbidden();
});
