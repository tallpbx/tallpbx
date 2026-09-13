<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\Backups\Jobs\RestoreRunner;
use Modules\Backups\Models\RestoreOperation;
use Modules\Backups\Services\BackupServiceInterface;
use Modules\Backups\Services\RestoreCommandRunnerInterface;
use Modules\Backups\Services\RestoreOperationExecutor;
use Modules\Backups\Services\RestoreService;
use Modules\Backups\Services\SymfonyRestoreCommandRunner;
use Modules\FileStores\Models\FileStore;

beforeEach(function (): void {
    Queue::fake();
    app()->bind(RestoreCommandRunnerInterface::class, SymfonyRestoreCommandRunner::class);

    if (! Schema::hasTable('backups')) {
        foreach (glob(__DIR__.'/../../../../app-modules/backups/database/migrations/*.php') as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
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
