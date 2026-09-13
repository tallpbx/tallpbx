<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use Modules\FileStores\Models\FileStore;

/**
 * Contract for reusable storage destination profiles.
 */
interface FileStoreServiceInterface
{
    /**
     * Provider identifiers supported by file store profiles.
     *
     * @var list<string>
     */
    public const Providers = [
        'local',
        's3',
        'ftp',
        'sftp',
        'ssh',
        'dropbox',
        'email',
    ];

    /**
     * Create a file store from validated provider data.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FileStore;

    /**
     * Update a file store from validated provider data.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FileStore $fileStore, array $data): FileStore;

    /**
     * Delete a file store profile without deleting its remote contents.
     */
    public function delete(FileStore $fileStore): void;

    /**
     * Verify that a file store can be reached.
     */
    public function testConnection(FileStore $fileStore): void;

    /**
     * List files beneath a provider-relative prefix.
     *
     * @return list<string>
     */
    public function listFiles(FileStore $fileStore, string $prefix = ''): array;

    /**
     * Determine whether a provider-relative file exists.
     */
    public function fileExists(FileStore $fileStore, string $path): bool;

    /**
     * Open a readable stream for a provider-relative file path.
     *
     * @return resource
     */
    public function readStream(FileStore $fileStore, string $path);

    /**
     * Write a stream to a provider-relative file path.
     *
     * @param  resource  $stream
     */
    public function writeStream(FileStore $fileStore, string $path, $stream): void;

    /**
     * Delete a provider-relative file path.
     */
    public function deleteFile(FileStore $fileStore, string $path): void;

    /**
     * Resolve an existing local object without allowing root or symlink escape.
     */
    public function resolveLocalPath(FileStore $fileStore, string $path): ?string;
}
