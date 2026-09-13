<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

/**
 * Provider adapter used by the file store service for stream operations.
 */
interface FileStoreAdapterInterface
{
    /**
     * Verify that the configured destination is reachable.
     */
    public function testConnection(): void;

    /**
     * Return paths for files under a provider-relative prefix.
     *
     * @return list<string>
     */
    public function listFiles(string $prefix): array;

    /**
     * Determine whether a provider-relative file exists.
     */
    public function fileExists(string $path): bool;

    /**
     * Open a readable stream for a provider-relative path.
     *
     * @return resource
     */
    public function readStream(string $path);

    /**
     * Write a resource stream to a provider-relative path.
     *
     * @param  resource  $stream
     */
    public function writeStream(string $path, $stream): void;

    /**
     * Delete a provider-relative file path.
     */
    public function deleteFile(string $path): void;
}
