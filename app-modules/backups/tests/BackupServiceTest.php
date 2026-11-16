<?php

declare(strict_types=1);

use App\Contracts\ProcessRunner;
use App\Support\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Backups\Jobs\BackupRunner;
use Modules\Backups\Models\Backup;
use Modules\Backups\Services\BackupManifest;
use Modules\Backups\Services\BackupService;
use Modules\Backups\Services\BackupServiceInterface;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Services\FileStoreServiceInterface;
use Modules\FileStores\Services\MediaStorageServiceInterface;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();

    // Keep the fallback for isolated module tests, but do not rerun a
    // migration that the application test bootstrap has already loaded.
    if (! Schema::hasTable('backups')) {
        foreach (glob(base_path('app-modules/backups/database/migrations/*.php')) as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
});

it('creates a backup record and dispatches a job', function (): void {
    /** @var BackupService $service */
    $service = app(BackupServiceInterface::class);

    $backup = $service->createBackup([
        'name' => 'Daily Backup',
        'scope' => ['database', 'app_files'],
        'retention_count' => 7,
        'compression' => true,
        'destination_disk' => 'local',
    ]);

    expect($backup)->toBeInstanceOf(Backup::class);
    expect($backup->name)->toBe('Daily Backup');
    expect($backup->scope)->toBe(['database', 'app_files']);
    expect($backup->retention_count)->toBe(7);
    expect($backup->compression)->toBeTrue();
    expect($backup->destination_disk)->toBe('local');
    expect($backup->file_store_id)->not->toBeNull()
        ->and($backup->fileStore->name)->toBe('Local storage - backups')
        ->and($backup->fileStore->provider)->toBe('local')
        ->and($backup->fileStore->settings['root'])->toBe(config('backup-storage.root'));
    // Status defaults to 'pending' from DB column default.
    // Queue::fake() prevents the runner from overwriting it.
    expect($backup->status)->toBeIn(['pending', null]);

    Queue::assertPushed(BackupRunner::class);
});

it('associates a backup configuration with a file store', function (): void {
    $fileStore = FileStore::create([
        'name' => 'Local backup destination',
        'provider' => 'local',
        'settings' => ['root' => storage_path('framework/testing/backups')],
    ]);

    /** @var BackupService $service */
    $service = app(BackupServiceInterface::class);

    $backup = $service->createBackup([
        'name' => 'File store backup',
        'scope' => ['database'],
        'retention_count' => 7,
        'compression' => true,
        'file_store_id' => $fileStore->id,
    ]);

    expect($backup->file_store_id)->toBe($fileStore->id)
        ->and($backup->fileStore->is($fileStore))->toBeTrue();
});

it('streams a completed archive and its manifest to the selected file store', function (): void {
    $storeRoot = storage_path('framework/testing/persisted-backups');
    File::deleteDirectory($storeRoot);

    $fileStore = FileStore::create([
        'name' => 'Archive persistence destination',
        'provider' => 'local',
        'settings' => ['root' => $storeRoot],
    ]);
    $backup = Backup::factory()->create([
        'file_store_id' => $fileStore->id,
        'scope' => ['database', 'media'],
    ]);
    $archivePath = tempnam(sys_get_temp_dir(), 'tallpbx-backup-');
    file_put_contents($archivePath, 'TallPBX persisted archive');

    try {
        /** @var BackupService $service */
        $service = app(BackupServiceInterface::class);
        $service->storeArchive($backup, $archivePath);

        $backup->refresh();
        $archiveContents = file_get_contents($storeRoot.'/'.$backup->last_file_path);
        $manifestContents = file_get_contents($storeRoot.'/'.$backup->manifest_path);

        expect($archiveContents)->toBe('TallPBX persisted archive')
            ->and($manifestContents)->toBeString()
            ->and(json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray([
                'format_version' => 1,
                'scopes' => ['database', 'media'],
                'archive' => [
                    'sha256' => hash_file('sha256', $archivePath),
                    'bytes' => filesize($archivePath),
                ],
            ])
            ->and($backup->archive_checksum)->toBe(hash_file('sha256', $archivePath))
            ->and($backup->archive_bytes)->toBe(filesize($archivePath));
    } finally {
        unlink($archivePath);
        File::deleteDirectory($storeRoot);
    }
});

it('uses the selected file store when a backup run completes', function (): void {
    $storeRoot = storage_path('framework/testing/runner-backups');
    File::deleteDirectory($storeRoot);

    $fileStore = FileStore::create([
        'name' => 'Runner archive destination',
        'provider' => 'local',
        'settings' => ['root' => $storeRoot],
    ]);
    $backup = Backup::factory()->create([
        'file_store_id' => $fileStore->id,
        'scope' => [],
        'compression' => false,
    ]);

    try {
        /** @var BackupService $service */
        $service = app(BackupServiceInterface::class);
        $service->run($backup);

        $backup->refresh();

        expect($backup->status)->toBe('completed')
            ->and($backup->last_file_path)->not->toBeNull()
            ->and($backup->manifest_path)->not->toBeNull()
            ->and(is_file($storeRoot.'/'.$backup->last_file_path))->toBeTrue()
            ->and(is_file($storeRoot.'/'.$backup->manifest_path))->toBeTrue()
            ->and($backup->archive_bytes)->toBeGreaterThan(0)
            ->and($backup->size_bytes)->toBe($backup->archive_bytes);
    } finally {
        File::deleteDirectory($storeRoot);
    }
});

it('creates unencrypted archives', function (): void {
    $storeRoot = storage_path('framework/testing/unencrypted-backups');
    File::deleteDirectory($storeRoot);
    $fileStore = FileStore::create([
        'name' => 'Unencrypted archive destination',
        'provider' => 'local',
        'settings' => ['root' => $storeRoot],
    ]);
    $backup = Backup::factory()->create([
        'file_store_id' => $fileStore->id,
        'scope' => [],
        'compression' => false,
    ]);

    try {
        /** @var BackupService $service */
        $service = app(BackupServiceInterface::class);
        $service->run($backup);
        $backup->refresh();

        expect($backup->status)->toBe('completed')
            ->and($backup->last_file_path)->not->toEndWith('.gpg');
    } finally {
        File::deleteDirectory($storeRoot);
    }
});

it('retains only complete file store archive runs', function (): void {
    $storeRoot = storage_path('framework/testing/retained-backups');
    File::deleteDirectory($storeRoot);

    $fileStore = FileStore::create([
        'name' => 'Retention archive destination',
        'provider' => 'local',
        'settings' => ['root' => $storeRoot],
    ]);
    $backup = Backup::factory()->create([
        'file_store_id' => $fileStore->id,
        'retention_count' => 1,
        'scope' => ['database'],
    ]);
    $firstArchivePath = tempnam(sys_get_temp_dir(), 'tallpbx-backup-');
    $secondArchivePath = tempnam(sys_get_temp_dir(), 'tallpbx-backup-');
    file_put_contents($firstArchivePath, 'first archive');
    file_put_contents($secondArchivePath, 'second archive');

    try {
        /** @var BackupService $backupService */
        $backupService = app(BackupServiceInterface::class);
        /** @var FileStoreServiceInterface $fileStoreService */
        $fileStoreService = app(FileStoreServiceInterface::class);

        $backupService->storeArchive($backup, $firstArchivePath);
        $backupService->storeArchive($backup, $secondArchivePath);
        $backup->refresh();

        expect($fileStoreService->listFiles($fileStore, 'backups/'.$backup->id))
            ->toEqualCanonicalizing([$backup->last_file_path, $backup->manifest_path]);
    } finally {
        unlink($firstArchivePath);
        unlink($secondArchivePath);
        File::deleteDirectory($storeRoot);
    }
});

it('continues using the configured disk for a legacy backup without a file store', function (): void {
    $backup = Backup::factory()->create([
        'file_store_id' => null,
        'scope' => [],
        'compression' => false,
        'destination_disk' => 'local',
    ]);

    /** @var BackupService $service */
    $service = app(BackupServiceInterface::class);
    $service->run($backup);

    $backup->refresh();

    expect($backup->status)->toBe('completed')
        ->and($backup->last_file_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($backup->last_file_path))->toBeTrue()
        ->and($backup->size_bytes)->toBeGreaterThan(0);
});

it('deletes file store archives when a backup configuration is deleted', function (): void {
    $storeRoot = storage_path('framework/testing/deleted-backups');
    File::deleteDirectory($storeRoot);

    $fileStore = FileStore::create([
        'name' => 'Deletion archive destination',
        'provider' => 'local',
        'settings' => ['root' => $storeRoot],
    ]);
    $backup = Backup::factory()->create([
        'file_store_id' => $fileStore->id,
        'scope' => ['database'],
    ]);
    $archivePath = tempnam(sys_get_temp_dir(), 'tallpbx-backup-');
    file_put_contents($archivePath, 'archive to delete');

    try {
        /** @var BackupService $backupService */
        $backupService = app(BackupServiceInterface::class);
        /** @var FileStoreServiceInterface $fileStoreService */
        $fileStoreService = app(FileStoreServiceInterface::class);

        $backupService->storeArchive($backup, $archivePath);
        $backupService->deleteBackup($backup);

        expect($fileStoreService->listFiles($fileStore, 'backups/'.$backup->id))->toBe([])
            ->and(Backup::find($backup->id))->toBeNull();
    } finally {
        unlink($archivePath);
        File::deleteDirectory($storeRoot);
    }
});

it('updates an existing backup configuration', function (): void {
    /** @var BackupService $service */
    $service = app(BackupServiceInterface::class);

    $backup = Backup::factory()->create([
        'name' => 'Weekly Backup',
        'retention_count' => 4,
    ]);

    $service->updateBackup($backup, [
        'name' => 'Monthly Backup',
        'retention_count' => 3,
    ]);

    $backup->refresh();

    expect($backup->name)->toBe('Monthly Backup');
    expect($backup->retention_count)->toBe(3);
});

it('deletes a backup configuration', function (): void {
    /** @var BackupService $service */
    $service = app(BackupServiceInterface::class);

    $backup = Backup::factory()->create();
    $backupId = $backup->id;

    $service->deleteBackup($backup);

    expect(Backup::find($backupId))->toBeNull();
});

it('generates correct dump command from database config', function (): void {
    /** @var BackupService $service */
    $service = app(BackupServiceInterface::class);

    $argv = $service->buildDumpCommand();

    // In test environment (SQLite), expect sqlite3 .dump
    // In production (MySQL/MariaDB), expect mysqldump
    expect($argv[0])->toBeIn(['mysqldump', 'sqlite3']);
});

it('never puts the database password on the dump command line', function (): void {
    $previous = config('database.default');

    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1', 'port' => '3306', 'database' => 'tallpbx',
            'username' => 'tallpbx', 'password' => 'se cret',
        ],
    ]);

    try {
        $argv = app(BackupServiceInterface::class)->buildDumpCommand('/tmp/dump.sql');
    } finally {
        // Restore the real test connection so nothing after this test tries to
        // reach the fictitious MySQL server above.
        config(['database.default' => $previous]);
    }

    expect($argv[0])->toBe('mysqldump')
        ->and(implode(' ', $argv))->not->toContain('se cret')
        ->and($argv)->toContain('--result-file=/tmp/dump.sql');
});

it('persists the SQLite dump from the command output', function (): void {
    // sqlite3 writes its dump to stdout and accepts no --result-file, so the
    // runner output must become database.sql or the backup is empty.
    config(['database.default' => 'sqlite']);

    $runner = new class implements ProcessRunner
    {
        /** @var array<int, string> */
        public array $argv = [];

        /** @var array<string, string> */
        public array $env = [];

        /**
         * Capture the dump command and return a scripted SQL payload.
         */
        public function run(
            array $argv,
            ?string $cwd = null,
            ?int $timeoutSeconds = null,
            array $env = [],
        ): ProcessResult {
            $this->argv = $argv;
            $this->env = $env;

            return new ProcessResult(0, 'CREATE TABLE x (id);');
        }
    };
    $this->app->instance(ProcessRunner::class, $runner);

    $tempDir = sys_get_temp_dir().'/tallpbx-dump-'.Str::random(8);
    File::ensureDirectoryExists($tempDir);

    $service = app(BackupServiceInterface::class);
    (new ReflectionMethod($service, 'dumpDatabase'))->invoke($service, $tempDir);

    expect(file_get_contents($tempDir.'/database.sql'))->toBe('CREATE TABLE x (id);')
        ->and($runner->argv)->toBe(['sqlite3', database_path('database.sqlite'), '.dump']);
});

it('includes configured scopes in backup', function (): void {
    /** @var BackupService $service */
    $service = app(BackupServiceInterface::class);

    $scopes = $service->availableScopes();

    expect($scopes)->toHaveKeys(['database', 'app_files', 'media', 'configuration']);
});

it('copies only registered managed local assets and retained spools into the media backup', function (): void {
    $managedRoot = storage_path('framework/testing/managed-backup-media');
    config([
        'media-storage.store_root' => $managedRoot.'/store',
        'media-storage.spool_root' => $managedRoot.'/spool',
    ]);
    $localPath = $managedRoot.'/store/runtime/1/recording/local.wav';
    $spoolPath = $managedRoot.'/spool/1/fax-outbound/pending.pdf';
    File::ensureDirectoryExists(dirname($localPath));
    File::ensureDirectoryExists(dirname($spoolPath));
    File::put($localPath, 'local managed media');
    File::put($spoolPath, 'retained remote spool');
    $storage = mock(MediaStorageServiceInterface::class);
    $storage->shouldReceive('localBackupEntries')->once()->andReturn([
        'local-asset' => $localPath,
        'failed-spool' => $spoolPath,
    ]);
    $service = new BackupService(mock(FileStoreServiceInterface::class), $storage);
    $workspace = storage_path('framework/testing/managed-backup-workspace');

    try {
        (new ReflectionMethod($service, 'archiveMedia'))->invoke($service, $workspace);

        expect(File::get($workspace.'/media/managed/store/runtime/1/recording/local.wav'))->toBe('local managed media')
            ->and(File::get($workspace.'/media/managed/spool/1/fax-outbound/pending.pdf'))->toBe('retained remote spool');
    } finally {
        File::deleteDirectory($managedRoot);
        File::deleteDirectory($workspace);
    }
});

it('builds a versioned manifest with an archive checksum', function (): void {
    $archivePath = tempnam(sys_get_temp_dir(), 'tallpbx-backup-');
    file_put_contents($archivePath, 'TallPBX archive contents');

    $manifest = BackupManifest::fromArchive(
        archivePath: $archivePath,
        scopes: ['database', 'media'],
    );

    expect($manifest->toArray())
        ->toMatchArray([
            'format_version' => 1,
            'scopes' => ['database', 'media'],
            'archive' => [
                'sha256' => hash_file('sha256', $archivePath),
                'bytes' => filesize($archivePath),
            ],
        ])
        ->toHaveKeys(['created_at', 'application_revision', 'freeswitch_version']);

    unlink($archivePath);
});
