<?php

declare(strict_types=1);

namespace Modules\TimeConditions\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\TimeConditionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model representing a time-based call routing condition.
 *
 * Each time condition belongs to a tenant and defines a time window
 * during which calls should be routed to a specific destination.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to the owning tenant
 * @property string $name Display name for this time condition
 * @property string|null $timezone Optional timezone override
 * @property string $weekdays Comma-separated weekday codes
 * @property string $start_time Start of the time window (H:i)
 * @property string $end_time End of the time window (H:i)
 * @property string|null $destination_on_match Route when matched
 * @property string|null $destination_on_no_match Route when not matched
 * @property bool $enabled Whether this condition is active
 */
class TimeCondition extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'timezone',
        'weekdays',
        'start_time',
        'end_time',
        'destination_on_match',
        'destination_on_no_match',
        'enabled',
    ];

    /**
     * The attributes that should be cast to native types.
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
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): TimeConditionFactory
    {
        return TimeConditionFactory::new();
    }
}
