<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use RuntimeException;

/**
 * Builds the portable metadata stored with every backup archive.
 */
class BackupManifest
{
    /**
     * Create a manifest from a completed archive on the local filesystem.
     *
     * @param  list<string>  $scopes
     */
    private function __construct(
        private readonly string $archivePath,
        private readonly array $scopes,
        private readonly ?string $backupId,
    ) {}

    /**
     * Create a manifest builder for an archive that has finished writing.
     *
     * @param  list<string>  $scopes
     */
    public static function fromArchive(string $archivePath, array $scopes, ?string $backupId = null): self
    {
        if (! is_file($archivePath)) {
            throw new RuntimeException('Backup archive does not exist.');
        }

        return new self($archivePath, $scopes, $backupId);
    }

    /**
     * Return the versioned, non-secret manifest payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $bytes = filesize($this->archivePath);
        $checksum = hash_file('sha256', $this->archivePath);

        if ($bytes === false || $checksum === false) {
            throw new RuntimeException('Backup archive could not be inspected.');
        }

        return [
            'format_version' => 1,
            'created_at' => now()->format('Y-m-d\\TH:i:s.vP'),
            'scopes' => $this->scopes,
            'backup_id' => $this->backupId,
            'archive' => [
                'sha256' => $checksum,
                'bytes' => $bytes,
            ],
            'application_revision' => $this->applicationRevision(),
            'freeswitch_version' => (string) config('freeswitch.version', 'unknown'),
        ];
    }

    /**
     * Resolve the local application revision without failing backup creation.
     */
    private function applicationRevision(): string
    {
        $revision = trim((string) shell_exec('git rev-parse --verify HEAD 2>/dev/null'));

        return $revision === '' ? 'unknown' : $revision;
    }
}
