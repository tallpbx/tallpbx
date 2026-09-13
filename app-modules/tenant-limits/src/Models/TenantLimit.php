<?php

declare(strict_types=1);

namespace Modules\TenantLimits\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\TenantLimitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores per-tenant resource limit configurations.
 *
 * Defines soft and hard limits for resources like extensions, calls, and recordings.
 */
class TenantLimit extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'resource', 'soft_limit', 'hard_limit',
    ];

    protected function casts(): array
    {
        return [
            'soft_limit' => 'integer',
            'hard_limit' => 'integer',
        ];
    }

    protected static function newFactory(): TenantLimitFactory
    {
        return TenantLimitFactory::new();
    }
}
