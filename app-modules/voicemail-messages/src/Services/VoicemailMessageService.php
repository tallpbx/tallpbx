<?php

declare(strict_types=1);

namespace Modules\VoicemailMessages\Services;

use App\Services\FreeSwitchServiceInterface;
use App\Support\CrudService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use RuntimeException;

/**
 * CRUD service for the VoicemailMessage model.
 */
class VoicemailMessageService extends CrudService
{
    /**
     * Create the service with local media and FreeSWITCH lifecycle support.
     */
    public function __construct(
        private readonly MediaStorageServiceInterface $mediaStorage,
        private readonly FreeSwitchServiceInterface $freeSwitch,
    ) {
        $this->modelClass = VoicemailMessage::class;
    }

    /**
     * Register a completed mod_voicemail file without remote replication.
     *
     * @param  array{tenant_id:int,voicemail_id:string,file_path:string,caller_id:?string,caller_id_name:?string,duration:int,message_uuid:string,domain:string,folder:string}  $data
     */
    public function registerCompleted(array $data): VoicemailMessage
    {
        return DB::transaction(function () use ($data): VoicemailMessage {
            $message = VoicemailMessage::withoutGlobalScope('tenant')->firstOrCreate(
                ['freeswitch_message_uuid' => $data['message_uuid']],
                [
                    'tenant_id' => $data['tenant_id'], 'voicemail_id' => $data['voicemail_id'],
                    'caller_id' => $data['caller_id'], 'caller_id_name' => $data['caller_id_name'],
                    'duration' => $data['duration'], 'file_path' => $data['file_path'], 'listened' => false,
                    'freeswitch_domain' => $data['domain'], 'freeswitch_folder' => $data['folder'],
                ],
            );
            if ($message->mediaAsset === null) {
                $this->mediaStorage->registerLocal($message, MediaCategory::VoicemailMessage, $data['file_path'], basename($data['file_path']), mime_content_type($data['file_path']) ?: 'audio/wav');
            }

            return $message->fresh(['mediaAsset']);
        });
    }

    /**
     * Remove a voicemail through mod_voicemail before deleting TallPBX state.
     */
    public function delete(Model $record): void
    {
        if (! $record instanceof VoicemailMessage || $record->freeswitch_message_uuid === null || $record->freeswitch_domain === null) {
            parent::delete($record);

            return;
        }
        if (! $this->freeSwitch->connect()) {
            throw new RuntimeException('FreeSWITCH is unavailable; voicemail was retained.');
        }
        try {
            $mailbox = $record->voicemail->mailbox;
            $delete = $this->freeSwitch->api("vm_fsdb_msg_delete default {$record->freeswitch_domain} {$mailbox} {$record->freeswitch_message_uuid}");
            $purge = $this->freeSwitch->api("vm_fsdb_msg_purge default {$record->freeswitch_domain} {$mailbox}");
            if (! str_starts_with($delete, '+OK') || ! str_starts_with($purge, '+OK')) {
                throw new RuntimeException('FreeSWITCH rejected voicemail deletion; voicemail was retained.');
            }
        } finally {
            $this->freeSwitch->disconnect();
        }
        DB::transaction(function () use ($record): void {
            if ($record->mediaAsset !== null) {
                $this->mediaStorage->requestDeletion($record->mediaAsset->id);
            }
            $record->delete();
        });
    }
}
