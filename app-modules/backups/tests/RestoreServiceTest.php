<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Backups\Jobs\RestoreRunner;
use Modules\Backups\Models\RestoreOperation;
use Modules\Backups\Services\BackupServiceInterface;
use Modules\Backups\Services\RestoreCommandRunnerInterface;
use Modules\Backups\Services\RestoreJournal;
use Modules\Backups\Services\RestoreOperationExecutor;
use Modules\Backups\Services\RestoreService;
use Modules\Backups\Services\SymfonyRestoreCommandRunner;
use Modules\FileStores\Models\FileStore;

/**
 * Write the claimed marker the dispatcher leaves for the root helper.
 */
function claimRestoreOperation(string $operationId, array $metadata = []): void
{
    $directory = (string) config('backup-storage.request_root');
    File::ensureDirectoryExists($directory, 0770, true);

    File::put($directory.'/'.$operationId.'.claimed', (string) json_encode(array_merge([
        'archive_path' => '/tmp/restore.tar',
        'archive_checksum' => hash('sha256', 'restore'),
        'source_backup_id' => null,
        'scopes' => ['database'],
        'requester' => null,
        'started_at' => now()->toIso8601String(),
    ], $metadata), JSON_THROW_ON_ERROR));
}

beforeEach(function (): void {
    Queue::fake();
    app()->bind(RestoreCommandRunnerInterface::class, SymfonyRestoreCommandRunner::class);

    // Isolate the helper handoff so no test reads or writes the appliance paths.
    $this->restoreHandoff = sys_get_temp_dir().'/restore-handoff-'.Str::random(8);
    config([
        'backup-storage.root' => $this->restoreHandoff.'/root',
        'backup-storage.request_root' => $this->restoreHandoff.'/requests',
        'backup-storage.journal_root' => $this->restoreHandoff.'/journal',
    ]);

    if (! Schema::hasTable('backups')) {
        foreach (glob(base_path('app-modules/backups/database/migrations/*.php')) as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->restoreHandoff);
});

it('rejects a restore request when the archive checksum does not match the manifest', function (): void {
    $archivePath = tempnam(sys_get_temp_dir(), 'tallpbx-restore-');
    file_put_contents($archivePath, 'archive contents');

    try {
        app(RestoreService::class)->inspectArchive($archivePath, [
            'archive' => ['sha256' => hash('sha256', 'different contents')],
        ]);
    } finally {
        unlink($archivePath);
    }
})->throws(RuntimeException::class, 'checksum');

it('rejects a restore request without an exact archive-name confirmation', function (): void {
    expect(fn () => app(RestoreService::class)->validateConfirmation('backup.tar', 'backup'))
        ->toThrow(RuntimeException::class, 'confirmation');
});

it('accepts an archive-name confirmation with surrounding whitespace', function (): void {
    expect(fn () => app(RestoreService::class)->validateConfirmation('backup.tar', '  backup.tar  '))
        ->not->toThrow(RuntimeException::class);
});

it('denies a tenant user before creating a restore operation', function (): void {
    $archivePath = tempnam(sys_get_temp_dir(), 'tallpbx-restore-');
    file_put_contents($archivePath, 'archive contents');

    try {
        $manifest = restoreManifest($archivePath);

        expect(fn () => app(RestoreService::class)->requestRestore(
            User::factory()->create(),
            $archivePath,
            basename($archivePath),
            $manifest,
        ))->toThrow(RuntimeException::class, 'authorized');
    } finally {
        unlink($archivePath);
    }
});

it('denies an admin without the restore permission', function (): void {
    $archivePath = tempnam(sys_get_temp_dir(), 'tallpbx-restore-');
    file_put_contents($archivePath, 'archive contents');

    try {
        expect(fn () => app(RestoreService::class)->requestRestore(
            Admin::factory()->create(),
            $archivePath,
            basename($archivePath),
            restoreManifest($archivePath),
        ))->toThrow(RuntimeException::class, 'authorized');
    } finally {
        unlink($archivePath);
    }
});

it('records a verified, confirmed restore request and dispatches the runner', function (): void {
    $archivePath = tempnam(sys_get_temp_dir(), 'tallpbx-restore-');
    file_put_contents($archivePath, 'archive contents');
    $admin = restoreAdmin();

    try {
        $operation = app(RestoreService::class)->requestRestore(
            $admin,
            $archivePath,
            basename($archivePath),
            restoreManifest($archivePath),
        )->fresh();

        expect($operation)->toBeInstanceOf(RestoreOperation::class)
            ->and($operation->requested_by_admin_id)->toBe($admin->id)
            ->and($operation->status)->toBe('queued')
            ->and($operation->archive_checksum)->toBe(hash_file('sha256', $archivePath))
            ->and($operation->scopes)->toBe(['database']);

        Queue::assertPushed(RestoreRunner::class);
    } finally {
        unlink($archivePath);
    }
});

/**
 * Build the minimal valid manifest used by restore request tests.
 *
 * @return array{archive: array{sha256: string}, scopes: list<string>}
 */
function restoreManifest(string $archivePath): array
{
    return [
        'archive' => ['sha256' => hash_file('sha256', $archivePath)],
        'scopes' => ['database'],
    ];
}

/**
 * Create an administrator with the one permission required to request a restore.
 */
function restoreAdmin(): Admin
{
    $permission = Permission::query()->firstOrCreate(
        ['name' => 'backups.restore'],
        ['module' => 'backups', 'description' => 'Restore backups'],
    );
    $group = Group::factory()->create(['name' => 'Super Administrators', 'tenant_id' => null]);
    $group->permissions()->attach($permission);
    $admin = Admin::factory()->create();
    $admin->groups()->attach($group);

    return $admin;
}

it('does not expose restore execution through the web-process backup contract', function (): void {
    expect((new ReflectionClass(BackupServiceInterface::class))->hasMethod('restore'))->toBeFalse();
});

it('stages a file store archive locally before restore validation', function (): void {
    $storeRoot = storage_path('framework/testing/restore-staging-source');
    $stagingRoot = storage_path('framework/testing/restore-staging-target');
    File::deleteDirectory($storeRoot);
    File::deleteDirectory($stagingRoot);
    File::ensureDirectoryExists($storeRoot);
    file_put_contents($storeRoot.'/archive.tar.gz', 'archive contents');
    $fileStore = FileStore::create([
        'name' => 'Restore source',
        'provider' => 'local',
        'settings' => ['root' => $storeRoot],
    ]);

    try {
        $stagedPath = app(RestoreService::class)->stageArchive($fileStore, 'archive.tar.gz', $stagingRoot);

        expect($stagedPath)->toStartWith($stagingRoot.'/')
            ->and($stagedPath)->toEndWith('.tar.gz')
            ->and(file_get_contents($stagedPath))->toBe('archive contents');
    } finally {
        File::deleteDirectory($storeRoot);
        File::deleteDirectory($stagingRoot);
    }
});

it('refuses restore operations that have not reached the privileged helper state', function (): void {
    $operation = RestoreOperation::create([
        'requested_by_admin_id' => Admin::factory()->create()->id,
        'archive_path' => '/tmp/restore.tar',
        'archive_checksum' => hash('sha256', 'restore'),
        'scopes' => ['database'],
        'status' => 'queued',
    ]);

    $this->artisan('backups:restore-operation', ['operation' => $operation->id])
        ->assertFailed();

    expect($operation->fresh()->status)->toBe('queued');
});

it('records a failed helper operation when its staged archive is unavailable', function (): void {
    $operation = RestoreOperation::create([
        'requested_by_admin_id' => Admin::factory()->create()->id,
        'archive_path' => storage_path('app/backups/restore-staging/missing.tar'),
        'archive_checksum' => hash('sha256', 'restore'),
        'scopes' => ['database'],
        'status' => 'pending-helper',
    ]);

    claimRestoreOperation($operation->id, ['archive_path' => $operation->archive_path]);

    $this->artisan('backups:restore-operation', ['operation' => $operation->id])
        ->assertFailed();

    $operation->refresh();

    expect($operation->status)->toBe('failed')
        ->and($operation->failure_reason)->toMatch('/archive/');
});

it('delegates a verified helper operation to the root restore executor', function (): void {
    $archivePath = tempnam(sys_get_temp_dir(), 'tallpbx-restore-');
    file_put_contents($archivePath, 'archive contents');
    $operation = RestoreOperation::create([
        'requested_by_admin_id' => Admin::factory()->create()->id,
        'archive_path' => $archivePath,
        'archive_checksum' => hash_file('sha256', $archivePath),
        'scopes' => ['database'],
        'status' => 'pending-helper',
    ]);

    try {
        $executor = mock(RestoreOperationExecutor::class);
        $executor->shouldReceive('execute')->once()->withArgs(fn (RestoreOperation $received): bool => $received->is($operation));
        app()->instance(RestoreOperationExecutor::class, $executor);

        claimRestoreOperation($operation->id, [
            'archive_path' => $archivePath,
            'archive_checksum' => hash_file('sha256', $archivePath),
        ]);

        $this->artisan('backups:restore-operation', ['operation' => $operation->id])
            ->assertSuccessful();
    } finally {
        unlink($archivePath);
    }
});

it('fails an operation before extraction when its archive lists a traversal path', function (): void {
    $operation = RestoreOperation::create([
        'requested_by_admin_id' => Admin::factory()->create()->id,
        'archive_path' => '/tmp/restore.tar',
        'archive_checksum' => hash('sha256', 'restore'),
        'scopes' => ['database'],
        'status' => 'pending-helper',
    ]);
    $runner = mock(RestoreCommandRunnerInterface::class);
    $runner->shouldReceive('run')->once()->with(['tar', '-tf', '/tmp/restore.tar'])->andReturn('../outside');
    // The failure path still leaves maintenance mode.
    $runner->shouldReceive('run')->with(['php', 'artisan', 'up'])->andReturn('');
    $runner->shouldReceive('run')->with(['php', 'artisan', 'backups:reconcile-restore-journal', '--operation=restore-operation-id'])->andReturn('');

    expect(fn () => (new RestoreOperationExecutor($runner))->execute($operation))
        ->toThrow(RuntimeException::class, 'unsafe path');
});

it('stores rollback snapshots outside the restored application-files tree', function (): void {
    $operation = new RestoreOperation;
    $operation->id = 'restore-operation-id';
    $executor = new RestoreOperationExecutor(mock(RestoreCommandRunnerInterface::class));
    $method = new ReflectionMethod($executor, 'snapshotDirectory');

    $snapshotDirectory = $method->invoke($executor, $operation);

    expect($snapshotDirectory)
        ->toBe(storage_path('backups/snapshots/restore-operation-id'))
        ->not->toStartWith(storage_path('app').DIRECTORY_SEPARATOR);
});

it('reports success without re-running an operation the journal already finished', function (): void {
    $operation = RestoreOperation::create([
        'requested_by_admin_id' => Admin::factory()->create()->id,
        'archive_path' => '/tmp/restore.tar',
        'archive_checksum' => hash('sha256', 'restore'),
        'scopes' => ['database'],
        'status' => 'pending-helper',
    ]);

    app(RestoreJournal::class)
        ->write($operation->id, ['phase' => 'completed', 'phase_at' => now()->toIso8601String()]);

    claimRestoreOperation($operation->id);

    $executor = mock(RestoreOperationExecutor::class);
    $executor->shouldNotReceive('execute');
    app()->instance(RestoreOperationExecutor::class, $executor);

    $this->artisan('backups:restore-operation', ['operation' => $operation->id])
        ->assertSuccessful();

    // The dispatcher's claim is retired so no stale marker survives.
    expect(file_exists($this->restoreHandoff.'/requests/'.$operation->id.'.claimed'))->toBeFalse();
});

it('refuses to start an unjournalled restore when the claim marker is missing', function (): void {
    $operation = RestoreOperation::create([
        'requested_by_admin_id' => Admin::factory()->create()->id,
        'archive_path' => '/tmp/restore.tar',
        'archive_checksum' => hash('sha256', 'restore'),
        'scopes' => ['database'],
        'status' => 'pending-helper',
    ]);

    $this->artisan('backups:restore-operation', ['operation' => $operation->id])
        ->assertFailed();

    expect($operation->fresh()->status)->toBe('pending-helper');
});

it('records the failure in the journal and projects it after the database is replaced', function (): void {
    $journalRoot = $this->restoreHandoff.'/journal';
    $operation = RestoreOperation::create([
        'requested_by_admin_id' => Admin::factory()->create()->id,
        'archive_path' => '/tmp/restore.tar',
        'archive_checksum' => hash('sha256', 'restore'),
        'scopes' => ['database'],
        'status' => 'pending-helper',
    ]);

    app(RestoreJournal::class)->write($operation->id, [
        'phase' => 'importing',
        'archive_path' => '/tmp/restore.tar',
        'archive_checksum' => $operation->archive_checksum,
        'scopes' => ['database'],
        'requester' => ['id' => 7, 'email' => 'admin@example.test', 'name' => 'Admin'],
    ]);

    // The imported database does not contain the row any more.
    RestoreOperation::query()->whereKey($operation->id)->delete();

    $runner = new class implements RestoreCommandRunnerInterface
    {
        /**
         * Simulate every phase, failing at the migration and reconciling in process.
         *
         * @param  array<int, string>  $command
         * @param  array<string, string>  $environment
         */
        public function run(array $command, mixed $input = null, array $environment = []): string
        {
            if (($command[0] ?? null) === 'tar' && ($command[1] ?? null) === '-tf') {
                return 'root/database.sql';
            }

            if (($command[0] ?? null) === 'tar' && str_starts_with((string) ($command[1] ?? ''), '-x')) {
                $target = $command[(int) array_search('-C', $command, true) + 1];
                File::ensureDirectoryExists($target.'/root');
                file_put_contents($target.'/root/database.sql', 'CREATE TABLE x (id);');

                return '';
            }

            if (($command[0] ?? null) === 'php' && ($command[2] ?? null) === 'migrate') {
                throw new RuntimeException('Migration failed after import.');
            }

            if (($command[0] ?? null) === 'php' && ($command[2] ?? null) === 'backups:reconcile-restore-journal') {
                Artisan::call('backups:reconcile-restore-journal', [
                    '--operation' => str_replace('--operation=', '', (string) ($command[3] ?? '')),
                ]);

                return '';
            }

            return '';
        }
    };

    $executor = new RestoreOperationExecutor(
        $runner,
        app(RestoreJournal::class),
        null,
        // Plenty of space; this test exercises the failure path, not preflight.
        static fn (string $path): float => 1_000_000_000_000.0,
    );

    try {
        expect(fn () => $executor->execute($operation))->toThrow(RuntimeException::class, 'Migration failed after import.');
    } finally {
        // The execution created an extraction workspace under the isolated root
        // and a rollback snapshot next to the stored backups.
        File::deleteDirectory($this->restoreHandoff.'/root');
        File::deleteDirectory(storage_path('backups/snapshots/'.$operation->id));
    }

    expect(app(RestoreJournal::class)->read($operation->id)['phase'])->toBe('failed');

    $this->assertDatabaseHas('restore_operations', [
        'id' => $operation->id,
        'status' => 'failed',
        'requested_by_name' => 'Admin',
        'requested_by_email' => 'admin@example.test',
    ]);
})->skip(fn (): bool => ! is_dir('/tmp'), 'Requires a writable temp root');
