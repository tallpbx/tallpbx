<?php

declare(strict_types=1);

namespace Modules\CallCenters\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Tier extends Model
{
    use HasUuids;

    protected $table = 'call_center_tiers';

    protected $fillable = ['queue_id', 'agent_id', 'level', 'position'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'position' => 'integer'];
    }
}
