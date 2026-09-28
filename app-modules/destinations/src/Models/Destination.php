<?php

declare(strict_types=1);

namespace Modules\Destinations\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A destination is a named endpoint that dialplan conditions route calls
 * to — conferences, IVRs, voicemail boxes, ring groups, etc.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string $type
 * @property string $dial_string
 * @property string|null $description
 * @property array|null $settings
 * @property bool $enabled
 */
class Destination extends Model
{
    /** @use HasFactory<Modules\Destinations\Database\Factories\DestinationFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'dial_string',
        'description',
        'settings',
        'enabled',
    ];

    /**
     * The tenant that owns this destination.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
