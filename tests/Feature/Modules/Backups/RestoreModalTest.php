<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Backups\Jobs\RestoreRunner;
use Modules\Backups\Livewire\RestoreArchive;
use Modules\Backups\Models\Backup;
use Modules\Backups\Models\RestoreOperation;
use Modules\Backups\Services\RestoreService;
use Modules\FileStores\Models\FileStore;

beforeEach(function (): void {
    Queue::fake();

    // Isolate restore staging per test file so parallel workers never share
    // (or clobber) the host-wide backup-staging directory.
    config(['backup-storage.root' => storage_path('framework/testing/restore-staging-RestoreModalTest')]);
    File::deleteDirectory((string) config('backup-storage.root'));

    if (! Schema::hasTable('backups')) {
        foreach (glob(__DIR__.'/../../../../app-modules/backups/database/migrations/*.php') as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
});

it('opens the typed confirmation modal for a stored archive', function (): void {
    $backup = restoreModalBackup();

    Livewire::actingAs(restoreModalAdmin(), 'admin')
        ->test(RestoreArchive::class)
        ->set('backupId', $backup->id)
        ->call('confirmStoredRestore')
        ->assertSet('confirmingRestore', true)
        ->assertSet('pendingRestoreName', 'archive.tar.gz')
        ->assertSee('Restore Backup?')
        ->assertSeeHtml('type="text"');
});

it('opens the typed confirmation modal for an uploaded archive', function (): void {
    $archive = UploadedFile::fake()->createWithContent('uploaded-backup.tar.gz', 'uploaded contents');
    $manifest = UploadedFile::fake()->createWithContent('backup-manifest.json', json_encode([
        'format_version' => 1,
        'scopes' => ['database'],
        'archive' => ['sha256' => hash('sha256', 'uploaded contents'), 'bytes' => strlen('uploaded contents')],
    ], JSON_THROW_ON_ERROR));

    Livewire::actingAs(restoreModalAdmin(), 'admin')
        ->test(RestoreArchive::class)
        ->set('archiveUpload', $archive)
        ->set('manifestUpload', $manifest)
        ->call('confirmUploadedRestore')
        ->assertSet('confirmingRestore', true)
        ->assertSet('pendingRestoreName', 'uploaded-backup.tar.gz');
});

it('keeps the restore pending when the typed archive name does not match', function (): void {
    $backup = restoreModalBackup();
    $stagingRoot = rtrim((string) config('backup-storage.root'), '/').'/restore-staging';
    $stagingBefore = File::isDirectory($stagingRoot) ? File::directories($stagingRoot) : [];

    Livewire::actingAs(restoreModalAdmin(), 'admin')
        ->test(RestoreArchive::class)
        ->set('backupId', $backup->id)
        ->call('confirmStoredRestore')
        ->set('confirmTypedInput', 'wrong.tar.gz')
        ->call('requestRestore')
        ->assertSet('restoreError', 'The typed text does not match. Nothing was changed.')
        ->assertSet('confirmingRestore', true);

    // The mismatch must be rejected before any new archive is staged.
    $stagingAfter = File::isDirectory($stagingRoot) ? File::directories($stagingRoot) : [];

    expect(RestoreOperation::query()->count())->toBe(0)
        ->and($stagingAfter)->toBe($stagingBefore);
});

it('requests the restore after the typed archive name matches', function (): void {
    $backup = restoreModalBackup();

    Livewire::actingAs(restoreModalAdmin(), 'admin')
        ->test(RestoreArchive::class)
        ->set('backupId', $backup->id)
        ->call('confirmStoredRestore')
        ->set('confirmTypedInput', 'archive.tar.gz')
        ->call('requestRestore')
        ->assertSet('confirmingRestore', false)
        ->assertSet('operationId', fn (?string $operationId): bool => $operationId !== null)
        ->assertHasNoErrors();

    expect(RestoreOperation::query()->sole()->scopes)->toBe(['database']);
    Queue::assertPushed(RestoreRunner::class);
});

it('keeps the modal open with a safe error when the restore service refuses', function (): void {
    $archive = UploadedFile::fake()->createWithContent('uploaded-backup.tar.gz', 'uploaded contents');
    $manifest = UploadedFile::fake()->createWithContent('backup-manifest.json', json_encode([
        'format_version' => 1,
        'scopes' => ['database'],
        'archive' => ['sha256' => hash('sha256', 'uploaded contents'), 'bytes' => strlen('uploaded contents')],
    ], JSON_THROW_ON_ERROR));

    $service = Mockery::mock(RestoreService::class);
    $service->shouldReceive('requestRestore')->once()->andThrow(new RuntimeException('The restore was rejected.'));
    app()->instance(RestoreService::class, $service);

    Livewire::actingAs(restoreModalAdmin(), 'admin')
        ->test(RestoreArchive::class)
        ->set('archiveUpload', $archive)
        ->set('manifestUpload', $manifest)
        ->call('confirmUploadedRestore')
        ->set('confirmTypedInput', 'uploaded-backup.tar.gz')
        ->call('requestRestore')
        ->assertSet('restoreError', 'The restore was rejected.')
        ->assertSet('confirmingRestore', true);

    expect(RestoreOperation::query()->count())->toBe(0);
});

it('restores the uploaded archive even when a stored backup is also selected', function (): void {
    $backup = restoreModalBackup();
    $archive = UploadedFile::fake()->createWithContent('uploaded-backup.tar.gz', 'uploaded contents');
    $manifest = UploadedFile::fake()->createWithContent('backup-manifest.json', json_encode([
        'format_version' => 1,
        'scopes' => ['database'],
        'archive' => ['sha256' => hash('sha256', 'uploaded contents'), 'bytes' => strlen('uploaded contents')],
    ], JSON_THROW_ON_ERROR));

    Livewire::actingAs(restoreModalAdmin(), 'admin')
        ->test(RestoreArchive::class)
        ->set('backupId', $backup->id)
        ->set('archiveUpload', $archive)
        ->set('manifestUpload', $manifest)
        ->call('confirmUploadedRestore')
        ->assertSet('pendingRestoreName', 'uploaded-backup.tar.gz')
        ->set('confirmTypedInput', 'uploaded-backup.tar.gz')
        ->call('requestRestore')
        ->assertSet('confirmingRestore', false)
        ->assertSet('operationId', fn (?string $operationId): bool => $operationId !== null)
        ->assertHasNoErrors();

    expect(RestoreOperation::query()->sole()->archive_path)->toEndWith('uploaded-backup.tar.gz');
    Queue::assertPushed(RestoreRunner::class);
});

it('cancelling the modal clears the confirmation state', function (): void {
    $backup = restoreModalBackup();

    Livewire::actingAs(restoreModalAdmin(), 'admin')
        ->test(RestoreArchive::class)
        ->set('backupId', $backup->id)
        ->call('confirmStoredRestore')
        ->set('confirmTypedInput', 'archive.tar.gz')
        ->call('cancelRestore')
        ->assertSet('confirmingRestore', false)
        ->assertSet('pendingRestoreName', null)
        ->assertSet('confirmTypedInput', '')
        ->assertSet('restoreError', null);

    expect(RestoreOperation::query()->count())->toBe(0);
});

/**
 * Create an administrator that is authorized to request system restores.
 */
function restoreModalAdmin(): Admin
{
    $permission = Permission::query()->firstOrCreate(
        ['name' => 'backups.restore'],
        ['module' => 'backups', 'description' => 'Restore backups'],
    );
    $group = Group::factory()->system()->create(['name' => 'Super Administrators']);
    $group->permissions()->sync([$permission->id]);
    $admin = Admin::factory()->create();
    $admin->groups()->attach($group);

    return $admin;
}

/**
 * Create a completed backup archive and its manifest in the local host store.
 */
function restoreModalBackup(): Backup
{
    // Uses a distinct fixture root from RestoreArchiveTest so the two files
    // can run in parallel workers without deleting each other's archives.
    $root = storage_path('framework/testing/restore-modal-ui');
    File::deleteDirectory($root);
    File::ensureDirectoryExists($root.'/backups/restore-ui/run');
    $archivePath = $root.'/backups/restore-ui/run/archive.tar.gz';
    file_put_contents($archivePath, 'restore archive');
    $checksum = hash_file('sha256', $archivePath);
    file_put_contents($root.'/backups/restore-ui/run/backup-manifest.json', json_encode([
        'format_version' => 1,
        'scopes' => ['database'],
        'archive' => ['sha256' => $checksum, 'bytes' => filesize($archivePath)],
        'application_revision' => 'test-revision',
    ], JSON_THROW_ON_ERROR));

    $fileStore = FileStore::create([
        'name' => 'Restore UI local host',
        'provider' => 'local',
        'settings' => ['root' => $root],
    ]);

    return Backup::factory()->create([
        'name' => 'Nightly local archive',
        'file_store_id' => $fileStore->id,
        'scope' => ['database'],
        'status' => 'completed',
        'last_file_path' => 'backups/restore-ui/run/archive.tar.gz',
        'manifest_path' => 'backups/restore-ui/run/backup-manifest.json',
        'archive_checksum' => $checksum,
        'archive_bytes' => filesize($archivePath),
    ]);
}
