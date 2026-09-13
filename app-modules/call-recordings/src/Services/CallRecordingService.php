<?php

declare(strict_types=1);

namespace Modules\CallRecordings\Services;

use App\Support\CrudService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\CallRecordings\Models\CallRecording;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;

/**
 * CRUD service for the CallRecording model.
 */
class CallRecordingService extends CrudService
{
    /**
     * Create the service with managed-media lifecycle support.
     */
    public function __construct(private readonly MediaStorageServiceInterface $mediaStorage)
    {
        $this->modelClass = CallRecording::class;
    }

    /**
     * Create and archive one completed FreeSWITCH recording atomically.
     *
     * @param  array{tenant_id: int, call_uuid: string, file_path: string, caller_id: string|null, caller_id_name: string|null, destination: string|null, duration: int, original_filename: string, mime_type: string}  $attributes
     */
    public function archiveCompleted(array $attributes): CallRecording
    {
        $recording = DB::transaction(function () use ($attributes): CallRecording {
            $existing = CallRecording::withoutGlobalScope('tenant')
                ->where('call_uuid', $attributes['call_uuid'])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $recording = CallRecording::withoutGlobalScope('tenant')->create([
                'tenant_id' => $attributes['tenant_id'],
                'caller_id' => $attributes['caller_id'],
                'caller_id_name' => $attributes['caller_id_name'],
                'destination' => $attributes['destination'],
                'duration' => $attributes['duration'],
                'file_path' => $attributes['file_path'],
                'call_uuid' => $attributes['call_uuid'],
            ]);

            $this->mediaStorage->archiveCompleted(
                $recording,
                MediaCategory::CallRecording,
                $attributes['file_path'],
                $attributes['original_filename'],
                $attributes['mime_type'],
            );

            return $recording;
        });

        File::delete($attributes['file_path']);

        return $recording->fresh(['mediaAsset']);
    }

    /**
     * Delete the owning row and its managed media object together.
     */
    public function delete(Model $record): void
    {
        if (! $record instanceof CallRecording) {
            parent::delete($record);

            return;
        }

        DB::transaction(function () use ($record): void {
            $asset = $record->mediaAsset;

            if ($asset !== null) {
                $this->mediaStorage->requestDeletion($asset->id);
            }

            $record->delete();
        });
    }
}
