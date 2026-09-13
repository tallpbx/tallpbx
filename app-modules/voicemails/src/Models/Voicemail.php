<?php

declare(strict_types=1);

namespace Modules\Voicemails\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\VoicemailFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\FileStores\Models\MediaAsset;

/**
 * A voicemail mailbox stores settings for receiving and managing
 * voice messages. Each voicemail is owned by a tenant and linked
 * to an extension via the voicemail_id/mailbox number. The mailbox
 * number is used by FreeSWITCH to route incoming voicemails.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $voicemail_id
 * @property string $mailbox
 * @property string|null $name
 * @property string|null $password
 * @property string|null $email
 * @property string|null $greeting_message
 * @property bool $require_password
 * @property bool $forward_to_email
 * @property bool $delete_after_email
 * @property bool $enabled
 */
class Voicemail extends Model
{
    /** @use HasFactory<VoicemailFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'voicemail_id',
        'mailbox',
        'name',
        'password',
        'email',
        'greeting_message',
        'require_password',
        'forward_to_email',
        'delete_after_email',
        'enabled',
    ];

    protected $hidden = [
        'password',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Return the managed local greeting asset. */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }

    protected static function newFactory(): VoicemailFactory
    {
        return VoicemailFactory::new();
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'require_password' => 'boolean',
            'forward_to_email' => 'boolean',
            'delete_after_email' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
