<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Backups\Jobs\BackupRunner;
use Modules\Backups\Livewire\BackupsEdit;
use Modules\Backups\Livewire\BackupsList;
use Modules\Backups\Models\Backup;
use Modules\Backups\Services\BackupServiceInterface;
use Modules\FileStores\Models\FileStore;

it('shows that a newly created backup has been queued on the backups list', function (): void {
    $fileStore = FileStore::query()->create([
        'name' => 'Backup feedback destination',
        'provider' => 'local',
        'settings' => ['root' => storage_path('framework/testing/backup-feedback')],
    ]);
    $backup = Backup::factory()->make(['name' => 'Nightly backup']);
    $service = Mockery::mock(BackupServiceInterface::class);
    $service->shouldReceive('availableScopes')->andReturn(['database' => 'Database (full SQL dump)']);
    $service->shouldReceive('createBackup')->once()->andReturn($backup);
    app()->instance(BackupServiceInterface::class, $service);

    Livewire::test(BackupsEdit::class)
        ->set('name', 'Nightly backup')
        ->set('scope', ['database'])
        ->set('fileStoreId', $fileStore->id)
        ->call('save')
        ->assertRedirect(route('panel.backups.index'));

    Livewire::test(BackupsList::class)
        ->assertSet('operationalMessageType', 'success')
        ->assertSet('operationalMessage', 'Backup “Nightly backup” was created and its first run has been queued.');
});

it('opens the shared confirmation modal before running a backup', function (): void {
    $backup = Backup::factory()->create(['name' => 'Nightly archive']);

    Livewire::test(BackupsList::class)
        ->call('confirmRunNow', $backup->id)
        ->assertSet('pendingRunId', $backup->id)
        ->assertSet('pendingRunName', 'Nightly archive')
        ->assertSee('Run Backup Now?');
});

it('ignores a run-now confirmation when nothing is pending', function (): void {
    // Guards against a double-click racing the cleared pending state: the
    // second invocation must be a no-op, not a 500.
    Livewire::test(BackupsList::class)
        ->call('runNow')
        ->assertOk()
        ->assertSet('pendingRunId', null);
});

it('opens the shared confirmation modal before deleting a backup', function (): void {
    $backup = Backup::factory()->create(['name' => 'Monthly archive']);

    Livewire::test(BackupsList::class)
        ->call('confirmBackupDeletion', $backup->id)
        ->assertSet('pendingDeletionId', $backup->id)
        ->assertSet('pendingDeletionName', 'Monthly archive')
        ->assertSee('Delete Backup?');
});

it('queues the backup run after modal confirmation', function (): void {
    Queue::fake();

    $backup = Backup::factory()->create(['name' => 'Nightly archive']);

    Livewire::test(BackupsList::class)
        ->call('confirmRunNow', $backup->id)
        ->call('runNow')
        ->assertSet('pendingRunId', null)
        ->assertSet('operationalMessage', 'Backup run queued.');

    Queue::assertPushed(BackupRunner::class);
    expect($backup->fresh()->status)->toBe('pending');
});
