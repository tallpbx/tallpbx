<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\CallBroadcastFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallBroadcast extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'status',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(CallBroadcastRecipient::class, 'broadcast_id');
    }

    protected static function newFactory(): CallBroadcastFactory
    {
        return CallBroadcastFactory::new();
    }
}
