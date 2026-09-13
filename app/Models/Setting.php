<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Key-value settings scoped to a tenant or system-wide.
 *
 * Settings are used for application configuration that can be
 * overridden per tenant. System settings have tenant_id = null.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'tenant_id',
    ];

    /**
     * The tenant this setting belongs to (null for system settings).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope to system settings (no tenant).
     */
    public function scopeSystem($query): void
    {
        $query->whereNull('tenant_id');
    }

    /**
     * Scope to settings for a specific tenant.
     */
    public function scopeForTenant($query, int $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }
}
