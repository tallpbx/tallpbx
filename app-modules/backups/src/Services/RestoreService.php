<?php

declare(strict_types=1);

namespace Modules\Backups\Services;

use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Backups\Jobs\RestoreRunner;
use Modules\Backups\Models\RestoreOperation;
use Modules\FileStores\Models\FileStore;
use Modules\FileStores\Services\FileStoreServiceInterface;
use RuntimeException;

/**
 * Validates bounded restore inputs before a privileged helper is invoked.
 */
class RestoreService
{
    /**
     * Create a restore service with access to configured file store streams.
     */
    public function __construct(private readonly FileStoreServiceInterface $fileStoreService) {}

    /**
     * Create a bounded restore operation without executing privileged shell work.
     *
     * @param  array{archive: array{sha256: string}, scopes: list<string>}  $manifest
     */
    public function requestRestore(Authenticatable $actor, string $archivePath, string $confirmation, array $manifest): RestoreOperation
    {
        if (! $actor instanceof Admin || ! $actor->hasPermission('backups.restore') || ! $this->isSuperAdministrator($actor)) {
            throw new RuntimeException('The administrator is not authorized to restore backups.');
        }

        $this->validateConfirmation(basename($archivePath), $confirmation);
        $this->inspectArchive($archivePath, $manifest);

        $operation = RestoreOperation::create([
            'requested_by_admin_id' => $actor->id,
            'archive_path' => $archivePath,
            'archive_checksum' => $manifest['archive']['sha256'],
            'source_backup_id' => $manifest['backup_id'] ?? null,
            'scopes' => $manifest['scopes'],
        ]);
        Bus::dispatch(new RestoreRunner($operation->id));

        return $operation;
    }

    /**
     * Stream a file store archive into a private local staging directory.
     *
     * The root-owned restore helper only receives a local staged file. This
     * keeps remote provider credentials and remote paths out of its interface.
     */
    public function stageArchive(FileStore $fileStore, string $archivePath, string $stagingDirectory): string
    {
        $extension = match (true) {
            str_ends_with($archivePath, '.tar.gz') => '.tar.gz',
            str_ends_with($archivePath, '.tar') => '.tar',
            default => throw new RuntimeException('Restore archives must use a .tar or .tar.gz extension.'),
        };
        $stagingPath = rtrim($stagingDirectory, '/').'/'.Str::uuid();
        $destination = $stagingPath.'/'.basename($archivePath);
        $partialDestination = $destination.'.part';

        File::ensureDirectoryExists($stagingPath, 0700, true);
        $source = $this->fileStoreService->readStream($fileStore, $archivePath);
        $target = fopen($partialDestination, 'xb');

        if ($target === false) {
            fclose($source);

            throw new RuntimeException('The restore archive staging file could not be created.');
        }

        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('The restore archive could not be staged from the file store.');
            }
        } catch (\Throwable $exception) {
            File::delete($partialDestination);

            throw $exception;
        } finally {
            fclose($source);
            fclose($target);
        }

        if (! rename($partialDestination, $destination)) {
            File::delete($partialDestination);

            throw new RuntimeException('The staged restore archive could not be finalized.');
        }

        chmod($destination, 0600);

        return $destination;
    }

    /**
     * Verify that a local archive matches the checksum recorded in its manifest.
     *
     * @param  array{archive: array{sha256: string}}  $manifest
     */
    public function inspectArchive(string $archivePath, array $manifest): void
    {
        if (! is_file($archivePath)) {
            throw new RuntimeException('The restore archive is not available.');
        }

        $checksum = hash_file('sha256', $archivePath);

        if ($checksum === false || ! hash_equals($manifest['archive']['sha256'], $checksum)) {
            throw new RuntimeException('The archive checksum does not match the manifest.');
        }
    }

    /**
     * Require the operator to type the exact selected archive name.
     */
    public function validateConfirmation(string $archiveName, string $confirmation): void
    {
        // Trim matches the component's typed-input gate, so a value with
        // surrounding whitespace is not rejected with a confusing message.
        if (! hash_equals($archiveName, trim($confirmation))) {
            throw new RuntimeException('The restore confirmation does not match the archive name.');
        }
    }

    /**
     * Restrict destructive restore requests to the system superadmin group.
     */
    private function isSuperAdministrator(Admin $admin): bool
    {
        return $admin->groups()
            ->where('name', 'Super Administrators')
            ->whereNull('tenant_id')
            ->exists();
    }
}
