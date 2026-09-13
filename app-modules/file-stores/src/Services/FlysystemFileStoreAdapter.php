<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use League\Flysystem\FilesystemOperator;

/**
 * Adapts a Flysystem filesystem to the File Stores service contract.
 */
class FlysystemFileStoreAdapter implements FileStoreAdapterInterface
{
    /**
     * Create an adapter around a Flysystem filesystem.
     */
    public function __construct(private readonly FilesystemOperator $filesystem) {}

    /**
     * Verify the destination by listing its root without changing contents.
     */
    public function testConnection(): void
    {
        iterator_to_array($this->filesystem->listContents('', false));
    }

    /**
     * Return file paths below the requested prefix.
     *
     * @return list<string>
     */
    public function listFiles(string $prefix): array
    {
        $files = [];

        foreach ($this->filesystem->listContents($prefix, true) as $attribute) {
            if ($attribute->isFile()) {
                $files[] = $attribute->path();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Determine whether a Flysystem object exists at the relative path.
     */
    public function fileExists(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }

    /**
     * Open a readable Flysystem stream.
     *
     * @return resource
     */
    public function readStream(string $path)
    {
        return $this->filesystem->readStream($path);
    }

    /**
     * Write a resource stream through Flysystem.
     *
     * @param  resource  $stream
     */
    public function writeStream(string $path, $stream): void
    {
        $this->filesystem->writeStream($path, $stream);
    }

    /**
     * Delete a provider-relative file path.
     */
    public function deleteFile(string $path): void
    {
        $this->filesystem->delete($path);
    }
}
