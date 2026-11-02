<?php

declare(strict_types=1);

namespace Modules\CallDetailRecords\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A FreeSWITCH call detail record owned by a tenant.
 */
class Cdr extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $table = 'cdrs';

    protected $fillable = [
        'tenant_id',
        'call_uuid',
        'b_leg_uuid',
        'leg',
        'caller_id',
        'caller_id_name',
        'destination',
        'duration',
        'billsec',
        'hangup_cause',
        'direction',
        'record_path',
        'start_stamp',
        'answer_stamp',
        'end_stamp',
        'json_data',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'billsec' => 'integer',
            'start_stamp' => 'datetime',
            'answer_stamp' => 'datetime',
            'end_stamp' => 'datetime',
            'json_data' => 'array',
        ];
    }
}
