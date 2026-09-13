<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use Modules\FileStores\Models\FileStore;

/**
 * Builds the appropriate provider adapter for a file store profile.
 */
interface FileStoreAdapterFactoryInterface
{
    /**
     * Create an adapter for the given destination profile.
     */
    public function make(FileStore $fileStore): FileStoreAdapterInterface;
}
