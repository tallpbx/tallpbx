<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bulk outbound calling campaign owned by a tenant.
 */
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

    /**
     * The phone recipients queued for this broadcast.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CallBroadcastRecipient::class, 'broadcast_id');
    }
}
