<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Backups\Jobs\RestoreRunner;
use Modules\Backups\Livewire\RestoreArchive;
use Modules\Backups\Models\Backup;
use Modules\Backups\Models\RestoreOperation;
use Modules\FileStores\Models\FileStore;

beforeEach(function (): void {
    Queue::fake();

    // Isolate restore staging per test file so parallel workers never share
    // (or clobber) the host-wide backup-staging directory.
    config(['backup-storage.root' => storage_path('framework/testing/restore-staging-RestoreArchiveTest')]);
    File::deleteDirectory((string) config('backup-storage.root'));

    if (! Schema::hasTable('backups')) {
        foreach (glob(__DIR__.'/../../../../app-modules/backups/database/migrations/*.php') as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
});

it('denies tenant users and administrators without restore permission', function (): void {
    $this->actingAs(User::factory()->create(), 'web')
        ->get(route('panel.backups.restore'))
        ->assertForbidden();

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get(route('panel.backups.restore'))
        ->assertForbidden();
});

it('renders a completed local file store archive and submits a confirmed restore request', function (): void {
    $admin = restoreArchiveAdmin();
    $backup = restoreArchiveBackup();

    Livewire::actingAs($admin, 'admin')
        ->test(RestoreArchive::class)
        ->assertOk()
        ->assertSee('archive.tar.gz')
        ->set('backupId', $backup->id)
        ->assertSee('Database')
        ->call('confirmStoredRestore')
        ->set('confirmTypedInput', 'archive.tar.gz')
        ->call('requestRestore')
        ->assertHasNoErrors()
        ->assertSet('operationId', fn (?string $operationId): bool => $operationId !== null);

    $operation = RestoreOperation::query()->sole();

    expect($operation->requested_by_admin_id)->toBe($admin->id)
        ->and($operation->scopes)->toBe(['database'])
        ->and($operation->archive_checksum)->toBe($backup->archive_checksum);
    Queue::assertPushed(RestoreRunner::class);
});

it('stages a verified uploaded archive before submitting its restore request', function (): void {
    $contents = 'uploaded restore archive';
    $archive = UploadedFile::fake()->createWithContent('uploaded-backup.tar.gz', $contents);
    $manifest = UploadedFile::fake()->createWithContent('backup-manifest.json', json_encode([
        'format_version' => 1,
        'scopes' => ['database'],
        'archive' => ['sha256' => hash('sha256', $contents), 'bytes' => strlen($contents)],
    ], JSON_THROW_ON_ERROR));

    Livewire::actingAs(restoreArchiveAdmin(), 'admin')
        ->test(RestoreArchive::class)
        ->set('archiveUpload', $archive)
        ->set('manifestUpload', $manifest)
        ->call('confirmUploadedRestore')
        ->set('confirmTypedInput', 'uploaded-backup.tar.gz')
        ->call('requestRestore')
        ->assertHasNoErrors();

    expect(RestoreOperation::query()->sole()->scopes)->toBe(['database']);
    Queue::assertPushed(RestoreRunner::class);
});

it('renders completed and failed restore operation states', function (): void {
    $admin = restoreArchiveAdmin();
    $operation = RestoreOperation::create([
        'requested_by_admin_id' => $admin->id,
        'archive_path' => '/var/lib/tallpbx/restore/archive.tar.gz',
        'archive_checksum' => hash('sha256', 'archive'),
        'scopes' => ['database'],
        'status' => 'failed',
        'failure_reason' => 'Archive checksum failed.',
        'pre_restore_snapshot_path' => '/var/lib/tallpbx/restore-snapshots/restore.tar.gz',
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(RestoreArchive::class)
        ->set('operationId', $operation->id)
        ->assertSee('Failed')
        ->assertSee('Archive checksum failed.')
        ->assertSee('restore-snapshots');
});

/**
 * Create an administrator that is authorized to request system restores.
 */
function restoreArchiveAdmin(): Admin
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
function restoreArchiveBackup(): Backup
{
    $root = storage_path('framework/testing/restore-archive-ui');
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
