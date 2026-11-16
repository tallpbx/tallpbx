<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use RuntimeException;

/**
 * Authoritative restore state kept outside the database the restore replaces.
 *
 * One process writes this journal: the privileged restore helper. The web and
 * queue processes never do — they hand metadata to the helper through the
 * request marker instead, because the journal directory is root-only. Each
 * operation is a single `<uuid>.json` file, written atomically and durably so
 * an abrupt shutdown cannot leave a truncated phase record behind.
 */
final class RestoreJournal
{
    /** Phases that end an operation and are never overwritten by later work. */
    private const TERMINAL_PHASES = ['completed', 'failed'];

    /**
     * Create a journal rooted at the given directory.
     */
    public function __construct(private readonly string $root) {}

    /**
     * Write a complete entry atomically and durably.
     *
     * @param  array<string, mixed>  $entry
     */
    public function write(string $operationId, array $entry): void
    {
        $this->ensureDirectory();

        $target = $this->path($operationId);
        $temporary = $target.'.tmp';

        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException('The restore journal file could not be created.');
        }

        try {
            $encoded = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            if (fwrite($handle, $encoded) === false) {
                throw new RuntimeException('The restore journal entry could not be written.');
            }

            fflush($handle);
            fsync($handle);
        } finally {
            fclose($handle);
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $target)) {
            @unlink($temporary);

            throw new RuntimeException('The restore journal entry could not be published.');
        }

        $this->syncDirectory();
    }

    /**
     * Seed the initial entry from the request marker, before anything is replaced.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function seed(string $operationId, array $metadata): void
    {
        $this->write($operationId, [
            'phase' => 'queued',
            'phase_at' => now()->toIso8601String(),
            'archive_path' => $metadata['archive_path'] ?? null,
            'archive_checksum' => $metadata['archive_checksum'] ?? null,
            'source_backup_id' => $metadata['source_backup_id'] ?? null,
            'scopes' => $metadata['scopes'] ?? [],
            'requester' => $metadata['requester'] ?? null,
            'started_at' => $metadata['started_at'] ?? now()->toIso8601String(),
        ]);
    }

    /**
     * Read one entry, or null when the operation has no journal.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $operationId): ?array
    {
        $path = $this->path($operationId);

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Merge a phase transition into the entry and stamp its time.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $operationId, string $phase, array $attributes = []): void
    {
        $entry = $this->read($operationId) ?? [];

        $this->write($operationId, [
            ...$entry,
            ...$attributes,
            'phase' => $phase,
            'phase_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Report the terminal phase of an operation, or null while it is unfinished.
     */
    public function terminalStatus(string $operationId): ?string
    {
        $phase = $this->read($operationId)['phase'] ?? null;

        return is_string($phase) && in_array($phase, self::TERMINAL_PHASES, true) ? $phase : null;
    }

    /**
     * Every readable journal entry, keyed by operation id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if (! is_dir($this->root)) {
            return [];
        }

        $entries = [];

        foreach (glob($this->root.'/*.json') ?: [] as $path) {
            $contents = @file_get_contents($path);
            $decoded = is_string($contents) ? json_decode($contents, true) : null;

            if (is_array($decoded)) {
                $entries[basename($path, '.json')] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * Return the file path for one operation.
     */
    private function path(string $operationId): string
    {
        $safeId = preg_replace('/[^0-9A-Za-z-]+/', '', $operationId) ?: 'unknown';

        return rtrim($this->root, '/').'/'.$safeId.'.json';
    }

    /**
     * Create or validate the root-only directory before any file is written.
     */
    private function ensureDirectory(): void
    {
        $this->assertNotSymlink();

        if (! is_dir($this->root) && ! @mkdir($this->root, 0700, true) && ! is_dir($this->root)) {
            throw new RuntimeException('The restore journal directory could not be created.');
        }

        $this->assertNotSymlink();

        $owner = fileowner($this->root);
        $mode = fileperms($this->root) & 0777;

        if ($owner !== getmyuid()) {
            throw new RuntimeException('The restore journal directory is owned by another account.');
        }

        if (($mode & 0077) !== 0) {
            @chmod($this->root, 0700);
            $mode = fileperms($this->root) & 0777;

            if (($mode & 0077) !== 0) {
                throw new RuntimeException('The restore journal directory is not owner-only.');
            }
        }
    }

    /**
     * Refuse a symlinked root so writes cannot be redirected outside the journal.
     */
    private function assertNotSymlink(): void
    {
        if (is_link($this->root)) {
            throw new RuntimeException('The restore journal directory must not be a symlink.');
        }
    }

    /**
     * Flush the directory entry so the rename survives an abrupt shutdown.
     */
    private function syncDirectory(): void
    {
        $handle = @fopen($this->root, 'r');

        if ($handle === false) {
            return;
        }

        @fsync($handle);
        fclose($handle);
    }
}
