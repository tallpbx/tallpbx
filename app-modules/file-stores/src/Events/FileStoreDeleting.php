<?php

declare(strict_types=1);

namespace Modules\FileStores\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\FileStores\Models\FileStore;

/**
 * Fired before a file store profile is deleted.
 *
 * Other modules listen for this event and may refuse the deletion by
 * throwing a RuntimeException — for example, the backups module blocks
 * stores that backup profiles still reference. This keeps the file stores
 * module free of dependencies on the modules that guard their own data.
 */
class FileStoreDeleting
{
    use Dispatchable;

    /**
     * Create the event with the file store being deleted.
     */
    public function __construct(public readonly FileStore $fileStore) {}
}
