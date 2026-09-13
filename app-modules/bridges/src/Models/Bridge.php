<?php

declare(strict_types=1);

namespace Modules\Bridges\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\BridgeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model representing a call bridge (conference room) destination.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $bridge_name
 * @property string $destination_number
 * @property string|null $pin_number
 * @property string|null $description
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Bridge extends Model
{
    /** @use HasFactory<BridgeFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'bridge_name',
        'destination_number',
        'pin_number',
        'description',
        'enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * The tenant to which this bridge belongs.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): BridgeFactory
    {
        return BridgeFactory::new();
    }
}
