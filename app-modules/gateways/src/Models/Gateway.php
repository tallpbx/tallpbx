<?php

declare(strict_types=1);

namespace Modules\Gateways\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\GatewayFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gateway represents a SIP trunk or upstream provider connection
 * used for outbound call routing. Stores host, port, credentials,
 * and optional registration settings.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $host
 * @property int $port
 * @property string|null $username
 * @property string|null $password
 * @property string|null $realm
 * @property string|null $proxy
 * @property bool $register
 * @property string $context
 * @property string|null $profile Sofia profile name (null/empty → 'external')
 * @property array|null $settings
 * @property bool $enabled
 */
class Gateway extends Model
{
    /** @use HasFactory<GatewayFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'host',
        'port',
        'username',
        'password',
        'realm',
        'proxy',
        'register',
        'context',
        'profile',
        'settings',
        'enabled',
    ];

    protected $hidden = [
        'password',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): GatewayFactory
    {
        return GatewayFactory::new();
    }

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'register' => 'boolean',
            'settings' => 'array',
            'password' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }
}
