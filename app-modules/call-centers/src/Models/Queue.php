<?php

declare(strict_types=1);

namespace Modules\CallCenters\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\CallCenterQueueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A call center queue that rings its assigned agents by strategy.
 */
class Queue extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $table = 'call_center_queues';

    protected $fillable = ['tenant_id', 'name', 'strategy', 'timeout', 'music_on_hold', 'enabled'];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return ['timeout' => 'integer', 'enabled' => 'boolean'];
    }

    /**
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): CallCenterQueueFactory
    {
        return CallCenterQueueFactory::new();
    }
}
