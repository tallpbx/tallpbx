<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone number within a call broadcast, with its call outcome.
 */
class CallBroadcastRecipient extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'broadcast_id',
        'phone_number',
        'originate_uuid',
        'call_status',
        'hangup_cause',
        'call_duration',
        'attempted_at',
    ];

    protected $table = 'call_broadcast_recipients';

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'call_duration' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * The broadcast this recipient belongs to.
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(CallBroadcast::class, 'broadcast_id');
    }
}
