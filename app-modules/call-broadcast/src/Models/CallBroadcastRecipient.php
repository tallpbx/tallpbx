<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Models;

use Database\Factories\Pbx\CallBroadcastRecipientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * Create a new factory instance for model seeding.
     */
    protected static function newFactory(): CallBroadcastRecipientFactory
    {
        return CallBroadcastRecipientFactory::new();
    }

    protected function casts(): array
    {
        return [
            'call_duration' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(CallBroadcast::class, 'broadcast_id');
    }
}
