<?php

declare(strict_types=1);

namespace Modules\VoicemailMessages\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\VoicemailMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\FileStores\Models\MediaAsset;
use Modules\Voicemails\Models\Voicemail;

/**
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string $voicemail_id
 * @property string|null $caller_id
 * @property string|null $caller_id_name
 * @property int $duration
 * @property string $file_path
 * @property bool $listened
 */
class VoicemailMessage extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'voicemail_id',
        'caller_id',
        'caller_id_name',
        'duration',
        'file_path',
        'listened',
        'freeswitch_message_uuid',
        'freeswitch_domain',
        'freeswitch_folder',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'listened' => 'boolean',
        ];
    }

    public function voicemail(): BelongsTo
    {
        return $this->belongsTo(Voicemail::class);
    }

    /**
     * Return the local media asset registered from FreeSWITCH.
     */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }

    protected static function newFactory(): VoicemailMessageFactory
    {
        return VoicemailMessageFactory::new();
    }
}
