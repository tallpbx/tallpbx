<?php

declare(strict_types=1);

namespace Modules\CallCenters\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A call center agent that receives calls from its queues.
 */
class Agent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'call_center_agents';

    protected $fillable = ['tenant_id', 'name', 'type', 'destination', 'enabled'];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
