<?php

declare(strict_types=1);

namespace Modules\CallCenters\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'call_center_agents';

    protected $fillable = ['tenant_id', 'name', 'type', 'destination', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
