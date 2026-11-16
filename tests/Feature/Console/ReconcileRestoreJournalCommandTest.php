<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Backups\Services\RestoreJournal;

/*
 * A restore replaces the very table that records its progress. The journal
 * projection puts the row back afterwards, including a snapshot of who asked
 * for the restore, because the restored `admins` table may reuse that id.
 */

beforeEach(function (): void {
    $this->journalRoot = sys_get_temp_dir().'/restore-journal-'.Str::random(8);
    config(['backup-storage.journal_root' => $this->journalRoot]);
    $this->uuid = (string) Str::uuid();
});

afterEach(function (): void {
    File::deleteDirectory($this->journalRoot);
});

it('recreates a restore operation row that the imported database erased', function (): void {
    $journal = new RestoreJournal($this->journalRoot);
    $journal->write($this->uuid, [
        'phase' => 'importing', 'phase_at' => now()->toIso8601String(),
        'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
        'archive_checksum' => str_repeat('a', 64), 'scopes' => ['database'],
        'requester' => ['id' => 7, 'email' => 'admin@example.test', 'name' => 'Admin'],
        'started_at' => '2026-11-15T10:00:00+00:00',
    ]);

    $this->artisan('backups:reconcile-restore-journal', ['--operation' => $this->uuid])->assertSuccessful();

    $this->assertDatabaseHas('restore_operations', [
        'id' => $this->uuid,
        'status' => 'importing',
        'requested_by_admin_id' => 7,
        'requested_by_name' => 'Admin',
        'requested_by_email' => 'admin@example.test',
    ]);
});

it('projects failure details and leaves unrelated operations alone', function (): void {
    $journal = new RestoreJournal($this->journalRoot);
    $journal->write($this->uuid, [
        'phase' => 'failed', 'phase_at' => now()->toIso8601String(),
        'archive_path' => '/var/lib/tallpbx/backups/x.tar.gz',
        'archive_checksum' => str_repeat('b', 64), 'scopes' => ['database'],
        'requester' => ['id' => 9, 'email' => 'other@example.test', 'name' => 'Other'],
        'failure_reason' => 'Import failed.',
        'pre_restore_snapshot_path' => '/var/backups/snapshot',
        'helper_log_path' => '/var/log/tallpbx-restore.log',
        'started_at' => '2026-11-15T10:00:00+00:00',
        'completed_at' => '2026-11-15T10:05:00+00:00',
    ]);

    $this->artisan('backups:reconcile-restore-journal')->assertSuccessful();

    $this->assertDatabaseHas('restore_operations', [
        'id' => $this->uuid,
        'status' => 'failed',
        'failure_reason' => 'Import failed.',
        'pre_restore_snapshot_path' => '/var/backups/snapshot',
        'helper_log_path' => '/var/log/tallpbx-restore.log',
        'requested_by_name' => 'Other',
        'requested_by_email' => 'other@example.test',
    ]);
});
