<?php

declare(strict_types=1);

namespace Modules\InboundRoutes\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\InboundRouteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inbound route maps an incoming DID/number to a destination
 * (extension, IVR, voicemail, etc.) with optional time conditions.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string $destination_number
 * @property string|null $caller_id_name
 * @property string|null $caller_id_number
 * @property string $action
 * @property string|null $action_data
 * @property int $priority
 * @property bool $enabled
 */
class InboundRoute extends Model
{
    /** @use HasFactory<InboundRouteFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'destination_number',
        'caller_id_name',
        'caller_id_number',
        'action',
        'action_data',
        'priority',
        'enabled',
    ];

    /**
     * Attribute casting configuration.
     */
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

    protected static function newFactory(): InboundRouteFactory
    {
        return InboundRouteFactory::new();
    }
}
