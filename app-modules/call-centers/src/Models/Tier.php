<?php

declare(strict_types=1);

namespace Modules\CallCenters\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Links one agent to one queue with a level and position.
 */
class Tier extends Model
{
    use HasUuids;

    protected $table = 'call_center_tiers';

    protected $fillable = ['queue_id', 'agent_id', 'level', 'position'];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return ['level' => 'integer', 'position' => 'integer'];
    }
}
