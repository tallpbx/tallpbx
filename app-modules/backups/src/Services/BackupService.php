<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Backups\Jobs\BackupRunner;
use Modules\Backups\Models\Backup;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Services\FileStoreServiceInterface;
use Modules\FileStores\Services\MediaStorageServiceInterface;

/**
 * Implements the backup and restore service.
 *
 * Backups are stored on the configured filesystem disk as timestamped
 * .tar.gz archives. The database is dumped via mysqldump (mariadb or
 * mysql driver) or sqlite3 .dump (sqlite driver). App files, media
 * (recordings/voicemail/fax), and configuration (.env, FreeSWITCH)
 * are tarred into the archive alongside the database dump.
 *
 * Retention pruning removes the oldest backups exceeding retention_count.
 */
class BackupService implements BackupServiceInterface
{
    /**
     * Create a backup service with access to the configured file store adapters.
     */
    public function __construct(
        private readonly FileStoreServiceInterface $fileStoreService,
        private readonly MediaStorageServiceInterface $mediaStorage,
    ) {}

    /**
     * Available backup scopes with human-readable labels.
     *
     * @return array<string, string>
     */
    public function availableScopes(): array
    {
        return [
            'database' => 'Database (full SQL dump)',
            'app_files' => 'Application files (Laravel storage, excluding media)',
            'media' => 'Media files (recordings, voicemail, fax)',
            'configuration' => 'Configuration (.env, config files, FreeSWITCH conf)',
        ];
    }

    /**
     * Create a new backup configuration and dispatch the runner job.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBackup(array $data): Backup
    {
        if (($data['file_store_id'] ?? null) === null) {
            $data['file_store_id'] = $this->defaultLocalFileStore()->id;
        }

        $backup = Backup::create($data);

        BackupRunner::dispatch($backup);

        return $backup;
    }

    /**
     * Update an existing backup configuration.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateBackup(Backup $backup, array $data): Backup
    {
        $backup->update($data);

        return $backup;
    }

    /**
     * Delete a backup configuration and remove stored backup files.
     */
    public function deleteBackup(Backup $backup): void
    {
        $fileStore = $backup->fileStore;

        if ($fileStore !== null) {
            foreach ($this->fileStoreService->listFiles($fileStore, 'backups/'.$backup->id) as $file) {
                $this->fileStoreService->deleteFile($fileStore, $file);
            }
        } else {
            // Remove all stored files for the legacy filesystem-disk configuration.
            $disk = Storage::disk($backup->destination_disk);
            $prefix = 'backups/'.$backup->id.'/';

            foreach ($disk->files($prefix) as $file) {
                $disk->delete($file);
            }
        }

        $backup->delete();
    }

    /**
     * Stream a completed local archive and its manifest to the selected file store.
     */
    public function storeArchive(Backup $backup, string $archivePath): void
    {
        $fileStore = $backup->fileStore;

        if ($fileStore === null) {
            throw new \RuntimeException('A backup file store must be selected before storing an archive.');
        }

        if ($fileStore->provider === 'email') {
            throw new \RuntimeException('Email file stores cannot retain restorable backup archives.');
        }

        $manifest = BackupManifest::fromArchive($archivePath, $backup->scope, $backup->id)->toArray();
        $runPath = sprintf('backups/%s/%s', $backup->id, now()->format('YmdHis').'-'.Str::uuid());
        $archiveDestination = $runPath.'/archive'.$this->archiveExtension($archivePath);
        $manifestDestination = $runPath.'/backup-manifest.json';
        $archiveStream = fopen($archivePath, 'rb');

        if ($archiveStream === false) {
            throw new \RuntimeException('Backup archive could not be opened for storage.');
        }

        try {
            $this->fileStoreService->writeStream($fileStore, $archiveDestination, $archiveStream);
        } finally {
            fclose($archiveStream);
        }

        $manifestStream = fopen('php://temp', 'w+b');

        if ($manifestStream === false) {
            throw new \RuntimeException('Backup manifest stream could not be created.');
        }

        try {
            fwrite($manifestStream, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            rewind($manifestStream);
            $this->fileStoreService->writeStream($fileStore, $manifestDestination, $manifestStream);
        } finally {
            fclose($manifestStream);
        }

        $this->pruneFileStoreBackups($backup, $fileStore);

        /** @var array{sha256: string, bytes: int} $archive */
        $archive = $manifest['archive'];

        $backup->update([
            'last_file_path' => $archiveDestination,
            'manifest_path' => $manifestDestination,
            'archive_checksum' => $archive['sha256'],
            'archive_bytes' => $archive['bytes'],
            'size_bytes' => $archive['bytes'],
        ]);
    }

    /**
     * Execute the backup process immediately.
     *
     * Orchestrates the full backup pipeline: database dump → file archive →
     * compression → storage → retention pruning.
     */
    public function run(Backup $backup): void
    {
        $backup->update(['status' => 'running', 'last_run_at' => now()]);

        try {
            $tempDir = rtrim((string) config('backup-storage.root'), '/').'/tmp/'.$backup->id.'_'.time();
            File::makeDirectory($tempDir, 0755, true);

            // Step 1: Database dump
            if (in_array('database', $backup->scope, true)) {
                $this->dumpDatabase($tempDir);
            }

            // Step 2: Copy app files into the temp directory
            if (in_array('app_files', $backup->scope, true)) {
                $this->archiveAppFiles($tempDir);
            }

            // Step 3: Copy media files
            if (in_array('media', $backup->scope, true)) {
                $this->archiveMedia($tempDir);
            }

            // Step 4: Copy configuration files
            if (in_array('configuration', $backup->scope, true)) {
                $this->archiveConfiguration($tempDir);
            }

            // Step 5: Create tar archive
            $timestamp = now()->format('Ymd-His');
            $fileName = $backup->id.'_'.$timestamp;
            $archivePath = rtrim((string) config('backup-storage.root'), '/').'/tmp/'.$fileName.'.tar';

            $this->createTarArchive($tempDir, $archivePath);

            // Step 6: Compress with gzip if enabled
            if ($backup->compression) {
                $this->gzipFile($archivePath);
                $archivePath .= '.gz';
                $fileName .= '.tar.gz';
            } else {
                $fileName .= '.tar';
            }

            // Step 7: Stream to the selected file store, retaining legacy disk support for existing profiles.
            if ($backup->file_store_id !== null) {
                $this->storeArchive($backup, $archivePath);
            } else {
                $this->storeLegacyArchive($backup, $archivePath, $fileName);
            }

            // Clean up temp files
            File::deleteDirectory($tempDir);
            if (file_exists($archivePath)) {
                unlink($archivePath);
            }

            $backup->update([
                'status' => 'completed',
                'last_success_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $backup->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the mysqldump (or sqlite3 .dump) command for the current
     * database connection using values from config/database.php.
     */
    public function buildDumpCommand(): string
    {
        $driver = DB::getDriverName();
        $connection = config('database.connections.'.DB::getDefaultConnection());

        if ($driver === 'sqlite') {
            $dbPath = database_path('database.sqlite');

            return 'sqlite3 '.escapeshellarg($dbPath).' .dump > '.rtrim((string) config('backup-storage.root'), '/').'/database.sql';
        }

        // mariadb or mysql
        $user = $connection['username'] ?? 'root';
        $password = $connection['password'] ?? '';
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? '3306';
        $database = $connection['database'] ?? 'tallpbx';

        $passArg = $password !== '' && $password !== null
            ? '-p'.escapeshellarg($password)
            : '';

        return sprintf(
            'mysqldump -h %s -P %s -u %s %s --single-transaction --quick --routines --triggers %s > %s',
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($user),
            $passArg,
            escapeshellarg($database),
            rtrim((string) config('backup-storage.root'), '/').'/database.sql'
        );
    }

    /**
     * Dump the database to a SQL file in the given directory.
     */
    private function dumpDatabase(string $tempDir): void
    {
        $dumpPath = $tempDir.'/database.sql';
        $cmd = str_replace(
            rtrim((string) config('backup-storage.root'), '/').'/database.sql',
            $dumpPath,
            $this->buildDumpCommand()
        );

        exec($cmd, result_code: $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Database dump failed with exit code '.$exitCode);
        }
    }

    /**
     * Archive Laravel application files (storage directory, excluding media subdirs).
     */
    private function archiveAppFiles(string $tempDir): void
    {
        $appDir = $tempDir.'/app_files';
        File::makeDirectory($appDir, 0755, true);

        // Copy storage directory excluding large media folders
        $storagePath = storage_path('app');
        if (is_dir($storagePath)) {
            foreach (File::directories($storagePath) as $dir) {
                $dirName = basename($dir);
                // Skip media-heavy directories that are backed up separately
                if (in_array($dirName, ['recordings', 'voicemail', 'fax', 'backups'], true)) {
                    continue;
                }
                File::copyDirectory($dir, $appDir.'/'.$dirName);
            }
            foreach (File::files($storagePath) as $file) {
                File::copy($file->getPathname(), $appDir.'/'.$file->getFilename());
            }
        }
    }

    /**
     * Archive media files (recordings, voicemail, fax) to the temp directory.
     */
    private function archiveMedia(string $tempDir): void
    {
        $mediaDir = $tempDir.'/media';
        File::makeDirectory($mediaDir, 0755, true);

        foreach (['recordings', 'voicemail', 'fax'] as $mediaType) {
            $path = storage_path('app/'.$mediaType);
            if (is_dir($path)) {
                File::copyDirectory($path, $mediaDir.'/'.$mediaType);
            }
        }

        foreach ($this->mediaStorage->localBackupEntries() as $path) {
            $destination = $this->managedBackupPath($path, $mediaDir);

            if ($destination === null) {
                continue;
            }

            File::ensureDirectoryExists(dirname($destination));
            File::copy($path, $destination);
        }
    }

    /**
     * Map a registered managed file to its bounded archive-relative path.
     */
    private function managedBackupPath(string $path, string $mediaDirectory): ?string
    {
        foreach (['store', 'spool'] as $area) {
            $root = realpath((string) config("media-storage.{$area}_root"));
            $resolved = realpath($path);

            if ($root !== false && $resolved !== false && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                return $mediaDirectory.'/managed/'.$area.'/'.substr($resolved, strlen($root.DIRECTORY_SEPARATOR));
            }
        }

        return null;
    }

    /**
     * Archive configuration files (.env, config/, FreeSWITCH config).
     */
    private function archiveConfiguration(string $tempDir): void
    {
        $configDir = $tempDir.'/configuration';
        File::makeDirectory($configDir, 0755, true);

        // .env file
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            copy($envPath, $configDir.'/.env');
        }

        // Laravel config directory
        $laravelConfigPath = base_path('config');
        if (is_dir($laravelConfigPath)) {
            File::copyDirectory($laravelConfigPath, $configDir.'/config');
        }

        // FreeSWITCH configuration (if present on the same server)
        $fsConfDir = '/etc/freeswitch';
        if (is_dir($fsConfDir)) {
            File::copyDirectory($fsConfDir, $configDir.'/freeswitch');
        }
    }

    /**
     * Create a tar archive from a directory.
     */
    private function createTarArchive(string $sourceDir, string $destPath): void
    {
        $parentDir = dirname($sourceDir);
        $dirName = basename($sourceDir);

        exec(sprintf(
            'tar -cf %s -C %s %s',
            escapeshellarg($destPath),
            escapeshellarg($parentDir),
            escapeshellarg($dirName)
        ), result_code: $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Failed to create tar archive.');
        }
    }

    /**
     * Gzip-compress a file in-place (adds .gz suffix).
     */
    private function gzipFile(string $filePath): void
    {
        exec('gzip '.escapeshellarg($filePath), result_code: $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Failed to compress backup file.');
        }
    }

    /**
     * Store a legacy archive on its configured Laravel filesystem disk.
     */
    private function storeLegacyArchive(Backup $backup, string $archivePath, string $fileName): void
    {
        $sizeBytes = filesize($archivePath);

        if ($sizeBytes === false) {
            throw new \RuntimeException('Backup archive could not be inspected before storage.');
        }

        $disk = Storage::disk($backup->destination_disk);
        $destination = 'backups/'.$backup->id.'/'.$fileName;
        $archiveStream = fopen($archivePath, 'rb');

        if ($archiveStream === false) {
            throw new \RuntimeException('Backup archive could not be opened for storage.');
        }

        try {
            if (! $disk->put($destination, $archiveStream)) {
                throw new \RuntimeException('Backup archive could not be written to the configured disk.');
            }
        } finally {
            fclose($archiveStream);
        }

        $this->pruneOldBackups($backup, $disk);

        $backup->update([
            'size_bytes' => $sizeBytes,
            'last_file_path' => $destination,
        ]);
    }

    /**
     * Return the extension chain for a local archive path.
     */
    private function archiveExtension(string $archivePath): string
    {
        $fileName = basename($archivePath);
        $firstDot = strpos($fileName, '.');

        return $firstDot === false ? '' : substr($fileName, $firstDot);
    }

    /**
     * Return the idempotent local destination used by legacy backup profiles.
     */
    private function defaultLocalFileStore(): FileStore
    {
        $fileStore = FileStore::query()->firstOrCreate(
            ['name' => 'Local storage - backups'],
            [
                'provider' => 'local',
                'settings' => ['root' => config('backup-storage.root')],
            ],
        );

        if (($fileStore->settings['root'] ?? null) !== config('backup-storage.root')) {
            $fileStore->update(['settings' => ['root' => config('backup-storage.root')]]);
        }

        return $fileStore;
    }

    /**
     * Remove completed file store runs that exceed the configured retention count.
     */
    private function pruneFileStoreBackups(Backup $backup, FileStore $fileStore): void
    {
        $prefix = 'backups/'.$backup->id;
        $files = $this->fileStoreService->listFiles($fileStore, $prefix);
        $completedRuns = collect($files)
            ->filter(fn (string $path): bool => str_ends_with($path, '/backup-manifest.json'))
            ->map(function (string $manifestPath) use ($fileStore): array {
                $manifestStream = $this->fileStoreService->readStream($fileStore, $manifestPath);

                try {
                    $contents = stream_get_contents($manifestStream);
                    $manifest = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);
                } finally {
                    fclose($manifestStream);
                }

                return [
                    'path' => $manifestPath,
                    'created_at' => $manifest['created_at'] ?? '',
                ];
            })
            ->sortBy('created_at')
            ->values();
        $expiredRuns = $completedRuns->slice(0, max(0, $completedRuns->count() - $backup->retention_count));

        foreach ($expiredRuns as $expiredRun) {
            $runPrefix = dirname($expiredRun['path']).'/';

            foreach ($files as $path) {
                if (str_starts_with($path, $runPrefix)) {
                    $this->fileStoreService->deleteFile($fileStore, $path);
                }
            }
        }
    }

    /**
     * Remove oldest backup files exceeding the retention count.
     *
     * @param  Filesystem  $disk
     */
    private function pruneOldBackups(Backup $backup, $disk): void
    {
        $prefix = 'backups/'.$backup->id.'/';
        $files = collect($disk->files($prefix))
            ->sort()
            ->values();

        $toDelete = $files->slice(0, max(0, $files->count() - $backup->retention_count));

        foreach ($toDelete as $file) {
            $disk->delete($file);
        }
    }
}
