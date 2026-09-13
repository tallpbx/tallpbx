<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

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
     * Create an executor with an argument-safe root command runner.
     */
    public function __construct(private readonly RestoreCommandRunnerInterface $commandRunner) {}

    /**
     * Execute the bounded restore operation after the command has verified it.
     */
    public function execute(RestoreOperation $operation): void
    {
        $this->assertArchiveIsSafe($operation->archive_path);

        $extractionDirectory = rtrim((string) config('backup-storage.root'), '/').'/restore-extract/'.$operation->id;
        File::deleteDirectory($extractionDirectory);
        File::ensureDirectoryExists($extractionDirectory, 0700, true);
        $extractCommand = str_ends_with($operation->archive_path, '.gz')
            ? ['tar', '-xzf', $operation->archive_path, '--no-same-owner', '--no-same-permissions', '-C', $extractionDirectory]
            : ['tar', '-xf', $operation->archive_path, '--no-same-owner', '--no-same-permissions', '-C', $extractionDirectory];
        $this->commandRunner->run($extractCommand);

        $snapshotDirectory = $this->snapshotDirectory($operation);
        $this->snapshotSelectedFiles($snapshotDirectory, $operation->scopes);

        $operation->update([
            'status' => 'running',
            'started_at' => now(),
            'failure_reason' => null,
            'pre_restore_snapshot_path' => $snapshotDirectory,
        ]);

        $this->commandRunner->run(['php', 'artisan', 'down']);

        try {
            $archiveRoot = $this->archiveRoot($extractionDirectory);
            $this->snapshotDatabase($snapshotDirectory, $operation->scopes);
            $this->restoreDatabase($archiveRoot, $operation->scopes);
            $this->reconcileRestoredBackupStatus($operation);
            $this->restoreSelectedFiles($archiveRoot, $operation->scopes);
            $this->commandRunner->run(['php', 'artisan', 'migrate', '--force']);
            $this->commandRunner->run(['php', 'artisan', 'optimize:clear']);
            $this->restartRuntimeServices();

            $operation->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $operation->update([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception;
        } finally {
            $this->commandRunner->run(['php', 'artisan', 'up']);
        }
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
            File::copyDirectory(storage_path('app'), $snapshotDirectory.'/app_files');
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
     * @param  list<string>  $scopes
     */
    private function restoreSelectedFiles(string $archiveRoot, array $scopes): void
    {
        if (in_array('app_files', $scopes, true) && is_dir($archiveRoot.'/app_files')) {
            foreach (File::directories($archiveRoot.'/app_files') as $source) {
                $destination = storage_path('app/'.basename($source));
                File::deleteDirectory($destination);
                File::copyDirectory($source, $destination);
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
        $database = (string) ($connection['database'] ?? '');
        $snapshotPath = $snapshotDirectory.'/database.sql';
        $command = ['mysqldump', '--host='.(string) ($connection['host'] ?? '127.0.0.1'), '--port='.(string) ($connection['port'] ?? '3306'), '--user='.(string) ($connection['username'] ?? 'root'), '--single-transaction', '--quick', '--routines', '--triggers', $database];
        $output = $this->commandRunner->run($command, environment: $this->databaseEnvironment($connection));
        File::put($snapshotPath, $output);
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
        $this->commandRunner->run($command, File::get($dumpPath), $this->databaseEnvironment($connection));
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

    /**
     * Restart known runtime units without failing when an optional unit is absent.
     */
    private function restartRuntimeServices(): void
    {
        foreach (['php8.5-fpm', 'nginx', 'freeswitch-listener', 'freeswitch'] as $service) {
            try {
                $this->commandRunner->run(['systemctl', 'try-restart', $service]);
            } catch (RuntimeException) {
                // A missing optional service must not obscure the restore result.
            }
        }

        $this->commandRunner->run(['php', 'artisan', 'queue:restart']);
    }
}
