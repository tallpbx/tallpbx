<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use Modules\FileStores\Models\FileStore;

/**
 * Resolves the one system-wide destination for eligible completed media.
 */
interface MediaArchiveDestinationServiceInterface
{
    /**
     * Return the configured readable destination or the local media fallback.
     */
    public function current(): FileStore;

    /**
     * Select a readable non-email destination for media archives.
     */
    public function set(FileStore $fileStore): void;
}
