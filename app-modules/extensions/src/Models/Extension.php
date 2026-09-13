<?php

declare(strict_types=1);

namespace Modules\Extensions\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\ExtensionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\SipAccounts\Models\SipAccount;

/**
 * An extension is a human-facing phone number within a tenant.
 * Extension numbers are unique per tenant — different tenants
 * can both have extension 101.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $extension_number
 * @property string|null $number_alias
 * @property string|null $display_name
 * @property bool $voicemail_enabled
 * @property bool $enabled
 */
class Extension extends Model
{
    /** @use HasFactory<ExtensionFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'extension_number',
        'number_alias',
        'display_name',
        'voicemail_enabled',
        'accountcode',
        'effective_caller_id_name',
        'effective_caller_id_number',
        'outbound_caller_id_name',
        'outbound_caller_id_number',
        'emergency_caller_id_name',
        'emergency_caller_id_number',
        'directory_first_name',
        'directory_last_name',
        'directory_visible',
        'directory_exten_visible',
        'max_registrations',
        'limit_max',
        'limit_destination',
        'missed_call_app',
        'missed_call_data',
        'user_context',
        'toll_allow',
        'call_timeout',
        'call_group',
        'call_screen_enabled',
        'user_record',
        'hold_music',
        'auth_acl',
        'cidr',
        'sip_force_contact',
        'sip_force_expires',
        'nibble_account',
        'mwi_account',
        'sip_bypass_media',
        'absolute_codec_string',
        'force_ping',
        'dial_string',
        'extension_language',
        'extension_dialect',
        'extension_voice',
        'extension_type',
        'description',
        'enabled',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Portal users assigned to this extension.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'extension_user')
            ->withTimestamps();
    }

    /**
     * Primary SIP account associated with this extension.
     */
    public function sipAccount(): HasOne
    {
        return $this->hasOne(SipAccount::class);
    }

    /**
     * All SIP accounts associated with this extension.
     */
    public function sipAccounts(): HasMany
    {
        return $this->hasMany(SipAccount::class);
    }

    protected static function newFactory(): ExtensionFactory
    {
        return ExtensionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'voicemail_enabled' => 'boolean',
            'directory_visible' => 'boolean',
            'directory_exten_visible' => 'boolean',
            'max_registrations' => 'integer',
            'limit_max' => 'integer',
            'call_timeout' => 'integer',
            'call_screen_enabled' => 'boolean',
            'sip_force_expires' => 'integer',
            'force_ping' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
