<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use App\Services\Appliance\RuntimeServiceReconciler;
use Closure;
use Illuminate\Support\Facades\File;
use Modules\Backups\Models\Backup;
use Modules\Backups\Models\RestoreOperation;
use RuntimeException;

/**
 * Executes the destructive phase of a restore operation under the root helper.
 */
class RestoreOperationExecutor
{
    /**
     * Authoritative lifecycle journal, resolved through the container when omitted.
     */
    private readonly RestoreJournal $journal;

    /**
     * Runtime service inspection and restart, resolved when omitted.
     */
    private readonly RuntimeServiceReconciler $services;

    /**
     * Free-space probe for one filesystem path, resolved when omitted.
     */
    private readonly Closure $freeSpace;

    /**
     * Create an executor with an argument-safe root command runner.
     */
    public function __construct(
        private readonly RestoreCommandRunnerInterface $commandRunner,
        ?RestoreJournal $journal = null,
        ?RuntimeServiceReconciler $services = null,
        ?Closure $freeSpace = null,
    ) {
        $this->journal = $journal ?? app(RestoreJournal::class);
        $this->services = $services ?? app(RuntimeServiceReconciler::class);
        $this->freeSpace = $freeSpace ?? static function (string $path): float {
            $free = @disk_free_space($path);

            return is_float($free) ? $free : 0.0;
        };
    }

    /**
     * Execute the bounded restore operation after the command has verified it.
     */
    public function execute(RestoreOperation $operation): void
    {
        $operationId = $operation->id;
        $deadline = new RestoreDeadline((int) config('backup-storage.restore_operation_deadline', 3600));
        $maintenanceStarted = false;

        if ($this->commandRunner instanceof RestoreDeadlineAwareRunner) {
            $this->commandRunner->setDeadline($deadline);
        }

        try {
            $this->phase($operationId, 'extracting', $deadline);
            $this->assertArchiveIsSafe($operation->archive_path);
            // The archive check is cheap and runs first; the space check needs
            // the member listing and must never mask an unsafe-path error.
            $this->assertSpaceFor($operation->archive_path, $operation->scopes);

            $extractionDirectory = rtrim((string) config('backup-storage.root'), '/').'/restore-extract/'.$operation->id;
            File::deleteDirectory($extractionDirectory);
            File::ensureDirectoryExists($extractionDirectory, 0700, true);
            $extractCommand = str_ends_with($operation->archive_path, '.gz')
                ? ['tar', '-xzf', $operation->archive_path, '--no-same-owner', '--no-same-permissions', '-C', $extractionDirectory]
                : ['tar', '-xf', $operation->archive_path, '--no-same-owner', '--no-same-permissions', '-C', $extractionDirectory];
            $this->commandRunner->run($extractCommand);

            $this->phase($operationId, 'snapshotting', $deadline);
            $snapshotDirectory = $this->snapshotDirectory($operation);
            $this->snapshotSelectedFiles($snapshotDirectory, $operation->scopes);

            $operation->update([
                'status' => 'running',
                'started_at' => now(),
                'failure_reason' => null,
                'pre_restore_snapshot_path' => $snapshotDirectory,
            ]);
            $this->journal->record($operationId, 'snapshotting', ['pre_restore_snapshot_path' => $snapshotDirectory]);

            $this->commandRunner->run(['php', 'artisan', 'down']);
            $maintenanceStarted = true;

            $archiveRoot = $this->archiveRoot($extractionDirectory);
            $this->phase($operationId, 'importing', $deadline);
            $this->snapshotDatabase($snapshotDirectory, $operation->scopes);
            $this->restoreDatabase($archiveRoot, $operation->scopes);
            $this->reconcileRestoredBackupStatus($operation);
            $this->phase($operationId, 'restoring-files', $deadline);
            $this->restoreFiles($archiveRoot, $operation->scopes);
            $this->phase($operationId, 'migrating', $deadline);
            $this->commandRunner->run(['php', 'artisan', 'migrate', '--force']);
            $this->phase($operationId, 'reconciling', $deadline);
            $this->commandRunner->run(['php', 'artisan', 'backups:reconcile-restore-journal', '--operation='.$operationId]);
            $operation->refresh();
            $this->commandRunner->run(['php', 'artisan', 'optimize:clear']);

            // Prove the runtime, not just the exit codes. The web tier is
            // probed only after leaving maintenance mode below.
            $this->phase($operationId, 'health-check', $deadline);
            $services = $this->services->reconcile();
            $this->journal->record($operationId, 'health-check', ['services' => $services]);

            $failed = [...$services['failed'], ...$services['probes']['failed']];

            if ($failed !== []) {
                throw new RuntimeException('Restore health checks failed: '.implode(', ', $failed));
            }

            $this->commandRunner->run(['php', 'artisan', 'up']);

            if (! $this->services->probeHttpHealth()) {
                $this->commandRunner->run(['php', 'artisan', 'down']);
                $this->journal->record($operationId, 'health-check', ['http_up' => false]);

                throw new RuntimeException('Restore health check failed: web tier did not return HTTP 200 on /up.');
            }

            $this->journal->record($operationId, 'completed', ['completed_at' => now()->toIso8601String()]);
            $operation->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // The journal is the only writer that survives a replaced database,
            // so it records the failure first. A half-restored runtime must
            // never take live traffic: the app stays down.
            $this->journal->record($operationId, 'failed', [
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now()->toIso8601String(),
                'maintenance_locked' => $maintenanceStarted,
            ]);

            // A reconcile failure must never mask the original exception.
            try {
                $this->commandRunner->run(['php', 'artisan', 'backups:reconcile-restore-journal', '--operation='.$operationId]);
            } catch (\Throwable) {
                // The journal already holds the authoritative failure.
            }

            try {
                $operation->update([
                    'status' => 'failed',
                    'failure_reason' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);
            } catch (\Throwable) {
                // The row may belong to the database the restore just replaced.
            }

            throw $exception;
        }
    }

    /**
     * Compute the disk requirement for a working set.
     *
     * The reserve covers the extraction overhead and leaves headroom for a
     * partly written dump; it is never smaller than two decimal gigabytes.
     *
     * @return array{workingSet: int, reserve: int, required: int}
     */
    public function projectedRequirement(int $workingSetBytes): array
    {
        $reserve = max(2_000_000_000, (int) ceil($workingSetBytes * 0.10));

        return [
            'workingSet' => $workingSetBytes,
            'reserve' => $reserve,
            'required' => $workingSetBytes + $reserve,
        ];
    }

    /**
     * Refuse a restore whose working set and reserve do not fit.
     *
     * The bound is the archive's own uncompressed member list, checked against
     * every filesystem the operation writes to: the extraction root, the
     * snapshot root, and the media root when media is selected.
     *
     * @param  list<string>  $scopes
     */
    public function assertSpaceFor(string $archivePath, array $scopes): void
    {
        $members = $this->listArchiveMembers($archivePath);
        $workingSet = array_sum($members);

        // The database dump and the media tree are written a second time into
        // the rollback snapshot, so their archived size counts twice.
        if (in_array('database', $scopes, true)) {
            $workingSet += $this->memberBytesMatching($members, '/database\\.sql$/');
        }

        if (in_array('media', $scopes, true)) {
            $workingSet += $this->memberBytesMatching($members, '#(^|/)media/#');
        }

        $requirement = $this->projectedRequirement($workingSet);
        $volumes = [
            rtrim((string) config('backup-storage.root'), '/'),
            storage_path('backups'),
        ];

        if (in_array('media', $scopes, true)) {
            $volumes[] = rtrim((string) config('media-storage.store_root'), '/');
        }

        foreach (array_unique($volumes) as $volume) {
            $probe = $volume;

            // Fresh appliances need not have created the snapshot or media
            // directory yet. Its nearest existing parent is on the filesystem
            // where the directory will be created.
            while (! is_dir($probe) && dirname($probe) !== $probe) {
                $probe = dirname($probe);
            }

            $available = ($this->freeSpace)($probe);

            if ($available < $requirement['required']) {
                throw new RuntimeException(sprintf(
                    'Refusing the restore: insufficient free space on %s (%d bytes free, %d required).',
                    $volume,
                    (int) $available,
                    $requirement['required'],
                ));
            }
        }
    }

    /**
     * List archive members with their uncompressed sizes.
     *
     * @return array<string, int> Path => size in bytes
     */
    private function listArchiveMembers(string $archivePath): array
    {
        $listCommand = str_ends_with($archivePath, '.gz')
            ? ['tar', '-tvzf', $archivePath]
            : ['tar', '-tvf', $archivePath];

        $members = [];

        foreach (preg_split('/\R/', trim($this->commandRunner->run($listCommand))) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) < 6) {
                continue;
            }

            // `tar -tv` is: mode links owner/group size date time name
            $members[(string) ($parts[5] ?? '')] = (int) ($parts[2] ?? 0);
        }

        return $members;
    }

    /**
     * Sum the sizes of members whose path matches the given pattern.
     *
     * @param  array<string, int>  $members
     */
    private function memberBytesMatching(array $members, string $pattern): int
    {
        $bytes = 0;

        foreach ($members as $path => $size) {
            if (preg_match($pattern, $path) === 1) {
                $bytes += $size;
            }
        }

        return $bytes;
    }

    /**
     * Reject absolute and traversal members before extracting an archive.
     */
    private function assertArchiveIsSafe(string $archivePath): void
    {
        $listCommand = str_ends_with($archivePath, '.gz')
            ? ['tar', '-tzf', $archivePath]
            : ['tar', '-tf', $archivePath];

        foreach (preg_split('/\R/', trim($this->commandRunner->run($listCommand))) ?: [] as $entry) {
            if ($entry === '' || str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $entry) === 1) {
                throw new RuntimeException('The restore archive contains an unsafe path.');
            }
        }
    }

    /**
     * Keep rollback snapshots outside storage/app because app-file restores copy that tree.
     */
    private function snapshotDirectory(RestoreOperation $operation): string
    {
        return storage_path('backups/snapshots/'.$operation->id);
    }

    /**
     * Copy only the selected mutable files into a rollback snapshot directory.
     *
     * @param  list<string>  $scopes
     */
    private function snapshotSelectedFiles(string $snapshotDirectory, array $scopes): void
    {
        File::deleteDirectory($snapshotDirectory);
        File::ensureDirectoryExists($snapshotDirectory, 0700, true);

        if (in_array('app_files', $scopes, true)) {
            // Mirror the backup's exclusion list: the pre-restore snapshot must
            // not duplicate the media and backup trees it protects, or every
            // restore doubles the disk footprint the preflight reserves.
            File::ensureDirectoryExists($snapshotDirectory.'/app_files', 0700, true);

            foreach (File::directories(storage_path('app')) as $directory) {
                if (in_array(basename($directory), ['recordings', 'voicemail', 'fax', 'backups'], true)) {
                    continue;
                }

                File::copyDirectory($directory, $snapshotDirectory.'/app_files/'.basename($directory));
            }

            foreach (File::files(storage_path('app')) as $file) {
                File::copy($file->getPathname(), $snapshotDirectory.'/app_files/'.$file->getFilename());
            }

            // Mirror the backup: third-party custom modules ride with the
            // app-files scope so a rollback snapshot can bring them back.
            $customModulesPath = base_path('custom-modules');

            if (is_dir($customModulesPath)) {
                File::copyDirectory($customModulesPath, $snapshotDirectory.'/custom_modules');
            }
        }

        if (in_array('media', $scopes, true)) {
            foreach (['recordings', 'voicemail', 'fax'] as $directory) {
                $source = storage_path('app/'.$directory);

                if (is_dir($source)) {
                    File::copyDirectory($source, $snapshotDirectory.'/media/'.$directory);
                }
            }

            $this->copyManagedMediaDirectories(
                source: null,
                destination: $snapshotDirectory.'/media/managed',
            );
        }

        if (in_array('configuration', $scopes, true)) {
            File::ensureDirectoryExists($snapshotDirectory.'/configuration', 0700, true);

            if (is_file(base_path('.env'))) {
                File::copy(base_path('.env'), $snapshotDirectory.'/configuration/.env');
            }

            File::copyDirectory(base_path('config'), $snapshotDirectory.'/configuration/config');

            if (is_dir('/etc/freeswitch')) {
                File::copyDirectory('/etc/freeswitch', $snapshotDirectory.'/configuration/freeswitch');
            }
        }
    }

    /**
     * Locate the single top-level directory created by the backup archive.
     */
    private function archiveRoot(string $extractionDirectory): string
    {
        $directories = File::directories($extractionDirectory);

        if (count($directories) !== 1) {
            throw new RuntimeException('The restore archive must contain one top-level directory.');
        }

        return $directories[0];
    }

    /**
     * Replace only file trees explicitly included by the restore request.
     *
     * Public so restored content is directly testable.
     *
     * @param  list<string>  $scopes
     */
    public function restoreFiles(string $archiveRoot, array $scopes): void
    {
        if (in_array('app_files', $scopes, true) && is_dir($archiveRoot.'/app_files')) {
            foreach (File::directories($archiveRoot.'/app_files') as $source) {
                $destination = storage_path('app/'.basename($source));
                File::deleteDirectory($destination);
                File::copyDirectory($source, $destination);
            }

            // Mirror the backup's member list: a top-level file next to the
            // directories must come back too. scandir (not the File facade)
            // also sees dot files.
            foreach (scandir($archiveRoot.'/app_files') ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || is_dir($archiveRoot.'/app_files/'.$entry)) {
                    continue;
                }

                File::copy($archiveRoot.'/app_files/'.$entry, storage_path('app/'.$entry));
            }
        }

        // Mirror the backup: a selected app-files restore also brings back
        // third-party custom modules. Only directories present in the archive
        // are replaced, so modules installed after the backup was taken
        // survive the restore; older archives without the member are skipped
        // by the is_dir guard.
        if (in_array('app_files', $scopes, true) && is_dir($archiveRoot.'/custom_modules')) {
            foreach (File::directories($archiveRoot.'/custom_modules') as $source) {
                $destination = base_path('custom-modules/'.basename($source));
                File::deleteDirectory($destination);
                File::copyDirectory($source, $destination);
            }

            // scandir (not the File facade): Symfony Finder skips dot entries,
            // so root-level artifacts like .gitkeep would otherwise never
            // make it back from the archive.
            foreach (scandir($archiveRoot.'/custom_modules') ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || is_dir($archiveRoot.'/custom_modules/'.$entry)) {
                    continue;
                }

                File::copy($archiveRoot.'/custom_modules/'.$entry, base_path('custom-modules/'.$entry));
            }
        }

        if (in_array('media', $scopes, true) && is_dir($archiveRoot.'/media')) {
            foreach (['recordings', 'voicemail', 'fax'] as $directory) {
                $source = $archiveRoot.'/media/'.$directory;

                if (is_dir($source)) {
                    $destination = storage_path('app/'.$directory);
                    File::deleteDirectory($destination);
                    File::copyDirectory($source, $destination);
                }
            }

            $this->copyManagedMediaDirectories(
                source: $archiveRoot.'/media/managed',
                destination: null,
            );
        }

        if (in_array('configuration', $scopes, true) && is_dir($archiveRoot.'/configuration')) {
            if (is_file($archiveRoot.'/configuration/.env')) {
                File::copy($archiveRoot.'/configuration/.env', base_path('.env'));
            }

            if (is_dir($archiveRoot.'/configuration/config')) {
                File::deleteDirectory(base_path('config'));
                File::copyDirectory($archiveRoot.'/configuration/config', base_path('config'));
            }

            if (is_dir($archiveRoot.'/configuration/freeswitch')) {
                File::deleteDirectory('/etc/freeswitch');
                File::copyDirectory($archiveRoot.'/configuration/freeswitch', '/etc/freeswitch');
            }
        }
    }

    /**
     * Copy bounded managed media between an archive/snapshot and configured roots.
     */
    private function copyManagedMediaDirectories(?string $source, ?string $destination): void
    {
        foreach (['store', 'spool'] as $area) {
            $configuredRoot = (string) config("media-storage.{$area}_root");
            $from = $source === null ? $configuredRoot : $source.'/'.$area;
            $to = $destination === null ? $configuredRoot : $destination.'/'.$area;

            if (is_dir($from)) {
                File::ensureDirectoryExists($to);
                File::copyDirectory($from, $to);
            }
        }
    }

    /**
     * Refuse a spent budget and record the phase with its remaining time.
     */
    private function phase(string $operationId, string $phase, RestoreDeadline $deadline): void
    {
        $deadline->assertRemaining();

        $this->journal->record($operationId, $phase, [
            'deadline_at' => $deadline->expiresAt()->toIso8601String(),
            'remaining_seconds' => $deadline->remainingSeconds(),
        ]);
    }

    /**
     * Export the current MySQL or MariaDB database before a database restore.
     *
     * @param  list<string>  $scopes
     */
    private function snapshotDatabase(string $snapshotDirectory, array $scopes): void
    {
        if (! in_array('database', $scopes, true)) {
            return;
        }

        $connection = config('database.connections.'.config('database.default'));
        $isSqlite = ((string) ($connection['driver'] ?? '')) === 'sqlite';
        $database = (string) ($connection['database'] ?? '');
        $snapshotPath = $snapshotDirectory.'/database.sql';

        if ($isSqlite) {
            // sqlite3 writes its dump to stdout and accepts no output flag.
            $output = $this->commandRunner->run(['sqlite3', $database, '.dump']);
            File::put($snapshotPath, $output);

            return;
        }

        // The client writes the file itself, so the dump never lives in memory.
        $command = ['mysqldump', '--host='.(string) ($connection['host'] ?? '127.0.0.1'), '--port='.(string) ($connection['port'] ?? '3306'), '--user='.(string) ($connection['username'] ?? 'root'), '--single-transaction', '--quick', '--routines', '--triggers', '--result-file='.$snapshotPath, $database];
        $this->commandRunner->run($command, environment: $this->databaseEnvironment($connection));
    }

    /**
     * Import the archive database dump through the configured MySQL client.
     *
     * @param  list<string>  $scopes
     */
    private function restoreDatabase(string $archiveRoot, array $scopes): void
    {
        if (! in_array('database', $scopes, true)) {
            return;
        }

        $dumpPath = $archiveRoot.'/database.sql';

        if (! is_file($dumpPath)) {
            throw new RuntimeException('The restore archive does not contain a database dump.');
        }

        $connection = config('database.connections.'.config('database.default'));
        $command = ['mysql', '--host='.(string) ($connection['host'] ?? '127.0.0.1'), '--port='.(string) ($connection['port'] ?? '3306'), '--user='.(string) ($connection['username'] ?? 'root'), (string) ($connection['database'] ?? '')];

        // Stream the dump through a file handle: Symfony's Process::setInput()
        // accepts a resource, so a multi-gigabyte dump never enters PHP memory.
        $handle = fopen($dumpPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The restore database dump could not be opened.');
        }

        try {
            $this->commandRunner->run($command, $handle, $this->databaseEnvironment($connection));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Mark the source backup complete after its own database snapshot is restored.
     */
    private function reconcileRestoredBackupStatus(RestoreOperation $operation): void
    {
        if ($operation->source_backup_id === null || ! in_array('database', $operation->scopes, true)) {
            return;
        }

        Backup::query()->whereKey($operation->source_backup_id)->update([
            'status' => 'completed',
            'last_success_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Supply the database password through the process environment only.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, string>
     */
    private function databaseEnvironment(array $connection): array
    {
        return ['MYSQL_PWD' => (string) ($connection['password'] ?? '')];
    }
}
