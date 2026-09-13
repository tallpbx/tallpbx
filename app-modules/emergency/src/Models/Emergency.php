<?php

declare(strict_types=1);

namespace Modules\Emergency\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\EmergencyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores per-tenant emergency (E911) configuration data.
 *
 * Each record defines the caller ID, physical address, and
 * geolocation coordinates for emergency calls originating
 * from a tenant. This data is provided to PSAPs when a
 * 911 call is placed.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string|null $caller_id Emergency caller ID number
 * @property string $address Physical street address for dispatch
 * @property string|null $latitude GPS latitude coordinate
 * @property string|null $longitude GPS longitude coordinate
 */
class Emergency extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'emergency_config';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id', 'caller_id', 'address', 'latitude', 'longitude',
    ];

    /**
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): EmergencyFactory
    {
        return EmergencyFactory::new();
    }
}
