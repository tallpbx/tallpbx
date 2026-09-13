<?php

declare(strict_types=1);

namespace Modules\MusicOnHold\Services;

use App\Support\CrudService;
use Illuminate\Database\Eloquent\Model;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\MusicOnHold\Models\MusicOnHold;

/**
 * CRUD service for the MusicOnHold model.
 */
class MusicOnHoldService extends CrudService
{
    public function __construct(private readonly MediaStorageServiceInterface $mediaStorage)
    {
        $this->modelClass = MusicOnHold::class;
    }

    /** Delete the managed music file before deleting its owner record. */
    public function delete(Model $record): void
    {
        /** @var MusicOnHold $record */
        if ($record->mediaAsset !== null) {
            $this->mediaStorage->requestDeletion($record->mediaAsset->id);
        }

        parent::delete($record);
    }
}
