<?php

declare(strict_types=1);

namespace Modules\SipAccounts\Models;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\SipAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Extensions\Models\Extension;

/**
 * A SIP account is the authentication identity FreeSWITCH uses to register
 * an endpoint. Supports three identity modes: global_username, domain_username,
 * and hybrid. Auth passwords are stored encrypted.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string|null $extension_id
 * @property int|null $tenant_domain_id
 * @property string $identity_mode
 * @property string $auth_username
 * @property string $auth_password
 * @property string $global_auth_key
 * @property string|null $user_context
 * @property bool $enabled
 */
class SipAccount extends Model
{
    /** @use HasFactory<SipAccountFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'extension_id',
        'tenant_domain_id',
        'identity_mode',
        'auth_username',
        'auth_password',
        'global_auth_key',
        'user_context',
        'enabled',
    ];

    protected $hidden = [
        'auth_password',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tenantDomain(): BelongsTo
    {
        return $this->belongsTo(TenantDomain::class);
    }

    /**
     * The PBX extension this SIP identity registers for.
     */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    protected static function newFactory(): SipAccountFactory
    {
        return SipAccountFactory::new();
    }

    protected function casts(): array
    {
        return [
            'auth_password' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }
}
