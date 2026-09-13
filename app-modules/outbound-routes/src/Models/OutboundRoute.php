<?php

declare(strict_types=1);

namespace Modules\OutboundRoutes\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\OutboundRouteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Gateways\Models\Gateway;

/**
 * An outbound route matches dialed numbers against patterns and
 * routes calls through a selected gateway with optional
 * caller ID manipulation.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string $dial_pattern
 * @property string|null $gateway Legacy free-form gateway name (deprecated, prefer gateway_id)
 * @property string|null $gateway_id UUID reference to the gateways table
 * @property string|null $caller_id_name
 * @property string|null $caller_id_number
 * @property int $priority
 * @property bool $enabled
 */
class OutboundRoute extends Model
{
    /** @use HasFactory<OutboundRouteFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'dial_pattern',
        'gateway',
        'gateway_id',
        'caller_id_name',
        'caller_id_number',
        'priority',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The gateway used for outbound bridging.
     *
     * Prefer gateway_id over the legacy gateway string column.
     * Named gatewayRelation to avoid conflict with the 'gateway' string attribute.
     */
    public function gatewayRelation(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }

    protected static function newFactory(): OutboundRouteFactory
    {
        return OutboundRouteFactory::new();
    }
}
