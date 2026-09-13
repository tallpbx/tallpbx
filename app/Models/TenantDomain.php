<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantDomainFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Domain names associated with a tenant for domain-based tenant detection.
 *
 * When a request comes in on a matching domain, the ScopeTenant middleware
 * can identify the tenant from the domain rather than requiring a session.
 */
class TenantDomain extends Model
{
    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'domain',
        'purpose',
        'enabled',
    ];

    /**
     * Attribute casting configuration.
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * The tenant this domain belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
