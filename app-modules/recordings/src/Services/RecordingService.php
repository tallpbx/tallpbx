<?php

declare(strict_types=1);

namespace Modules\Recordings\Services;

use App\Support\CrudService;
use Illuminate\Database\Eloquent\Model;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\Recordings\Models\Recording;

/**
 * CRUD service for the Recording model.
 */
class RecordingService extends CrudService
{
    public function __construct(private readonly MediaStorageServiceInterface $mediaStorage)
    {
        $this->modelClass = Recording::class;
    }

    /** Delete the managed recording object before deleting its owner record. */
    public function delete(Model $record): void
    {
        /** @var Recording $record */
        if ($record->mediaAsset !== null) {
            $this->mediaStorage->requestDeletion($record->mediaAsset->id);
        }

        parent::delete($record);
    }
}
