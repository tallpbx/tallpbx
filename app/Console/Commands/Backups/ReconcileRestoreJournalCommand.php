<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Backups\Models\RestoreOperation;
use Modules\Backups\Services\RestoreJournal;

/**
 * Projects the root-only restore journal back into the restored database.
 *
 * A restore replaces the tables that track its own progress, so the journal is
 * the authority. This command runs after the import (and again from the failure
 * path) to recreate or refresh one `restore_operations` row per journal entry.
 *
 * `requested_by_admin_id` is intentionally NOT a foreign key and must never be
 * turned into one: after a restore the `admins` table may have reused the id,
 * so the numeric id and the projected requester name/email are an opaque audit
 * record. The panel renders the projected columns instead of joining `admins`.
 */
#[Signature('backups:reconcile-restore-journal {--operation= : Limit to one restore operation UUID}')]
#[Description('Project restore journal state into the restored restore_operations table')]
class ReconcileRestoreJournalCommand extends Command
{
    /**
     * Recreate or refresh the projected rows.
     */
    public function handle(RestoreJournal $journal): int
    {
        $only = $this->option('operation');
        $entries = $journal->all();

        if (is_string($only) && $only !== '') {
            $entries = array_intersect_key($entries, [$only => true]);
        }

        foreach ($entries as $operationId => $entry) {
            RestoreOperation::query()->updateOrCreate(['id' => (string) $operationId], [
                'archive_path' => $entry['archive_path'] ?? null,
                'archive_checksum' => $entry['archive_checksum'] ?? null,
                'source_backup_id' => $entry['source_backup_id'] ?? null,
                'scopes' => $entry['scopes'] ?? [],
                'status' => $entry['phase'] ?? 'queued',
                'failure_reason' => $entry['failure_reason'] ?? null,
                'pre_restore_snapshot_path' => $entry['pre_restore_snapshot_path'] ?? null,
                'helper_log_path' => $entry['helper_log_path'] ?? null,
                'started_at' => $entry['started_at'] ?? null,
                'completed_at' => $entry['completed_at'] ?? null,
                'requested_by_admin_id' => $entry['requester']['id'] ?? null,
                'requested_by_name' => $entry['requester']['name'] ?? null,
                'requested_by_email' => $entry['requester']['email'] ?? null,
            ]);
        }

        return self::SUCCESS;
    }
}
