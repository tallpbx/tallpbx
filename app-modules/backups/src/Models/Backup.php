<?php

declare(strict_types=1);

namespace Modules\Backups\Models;

use Database\Factories\Pbx\BackupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FileStores\Models\FileStore;

/**
 * A backup configuration and run-history record.
 *
 * Each row represents both a backup profile (what to back up, on what
 * schedule) and the status of the most recent run. The BackupRunner
 * job reads the profile, executes the backup, and updates this record.
 *
 * @property string $id
 * @property string $name
 * @property array $scope Array of backup scopes (database, app_files, media, configuration)
 * @property int $retention_count Number of backup files to keep before pruning oldest
 * @property bool $compression Whether to gzip the backup archive
 * @property string $destination_disk Filesystem disk for backup storage
 * @property string|null $schedule_cron Cron expression for scheduled runs
 * @property string $status pending, running, completed, failed
 * @property int|null $size_bytes Size of last successful backup
 * @property string|null $last_file_path Path to last backup file on disk
 * @property string|null $last_run_at
 * @property string|null $last_success_at
 * @property string|null $last_error Error message from last failure
 * @property bool $notify_on_success
 * @property bool $notify_on_failure
 */
class Backup extends Model
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory, HasUuids;

    /**
     * Create a new factory instance for the model.
     *
     * Overrides the default HasFactory resolution so tests can use
     * Backup::factory() regardless of the factory's filesystem location.
     */
    protected static function newFactory()
    {
        return BackupFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'scope',
        'retention_count',
        'compression',
        'destination_disk',
        'file_store_id',
        'manifest_path',
        'archive_checksum',
        'archive_bytes',
        'schedule_cron',
        'status',
        'size_bytes',
        'last_file_path',
        'last_run_at',
        'last_success_at',
        'last_error',
        'notify_on_success',
        'notify_on_failure',
    ];

    /**
     * Attribute casting configuration.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'retention_count' => 'integer',
            'compression' => 'boolean',
            'size_bytes' => 'integer',
            'archive_bytes' => 'integer',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'notify_on_success' => 'boolean',
            'notify_on_failure' => 'boolean',
        ];
    }

    /**
     * Return the file store that receives this backup archive.
     */
    public function fileStore(): BelongsTo
    {
        return $this->belongsTo(FileStore::class);
    }
}
