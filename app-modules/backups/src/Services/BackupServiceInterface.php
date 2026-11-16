<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use Modules\Backups\Models\Backup;

/**
 * Contract for the backup and restore service layer.
 */
interface BackupServiceInterface
{
    /**
     * Create a new backup configuration and optionally dispatch the runner.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBackup(array $data): Backup;

    /**
     * Update an existing backup configuration.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateBackup(Backup $backup, array $data): Backup;

    /**
     * Delete a backup configuration and its stored files.
     */
    public function deleteBackup(Backup $backup): void;

    /**
     * Stream a completed local archive and its manifest to the selected file store.
     */
    public function storeArchive(Backup $backup, string $archivePath): void;

    /**
     * Execute the backup process immediately for a given configuration.
     *
     * Creates a temporary directory, dumps the database, tars up files
     * based on the configured scopes, compresses, stores to the selected
     * File Store, and updates the Backup record.
     */
    public function run(Backup $backup): void;

    /**
     * Build the database dump command as an argument vector.
     *
     * The result never carries the database password: callers pass it through
     * the process environment (`MYSQL_PWD`) so it cannot appear in the process
     * table or a shell history.
     *
     * @return array<int, string>
     */
    public function buildDumpCommand(?string $destinationPath = null): array;

    /**
     * Get the list of available backup scopes with labels.
     *
     * @return array<string, string>
     */
    public function availableScopes(): array;
}
