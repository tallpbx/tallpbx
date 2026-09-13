<?php

declare(strict_types=1);

namespace Modules\Backups\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Records the immutable inputs and lifecycle state for a privileged restore.
 */
class RestoreOperation extends Model
{
    use HasUuids;

    protected $fillable = [
        'requested_by_admin_id',
        'archive_path',
        'archive_checksum',
        'source_backup_id',
        'scopes',
        'status',
        'failure_reason',
        'pre_restore_snapshot_path',
        'helper_log_path',
        'started_at',
        'completed_at',
    ];

    /**
     * Cast persisted restore scopes and lifecycle timestamps to native values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scopes' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
