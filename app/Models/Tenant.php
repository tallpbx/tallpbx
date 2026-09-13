<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A tenant represents an organization or customer in the multi-tenant system.
 *
 * Tenants group users and PBX resources into isolated domains. Customer
 * tenants can have primary contacts, while the Default tenant owns shared
 * admin-created resources.
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /** Customer tenant for normal organization-owned resources. */
    public const string PURPOSE_CUSTOMER = 'customer';

    /** Default tenant for shared admin-owned PBX resources. */
    public const string PURPOSE_DEFAULT = 'default';

    protected $fillable = [
        'name',
        'slug',
        'purpose',
        'primary_user_id',
        'enabled',
    ];

    protected $attributes = [
        'purpose' => self::PURPOSE_CUSTOMER,
        'enabled' => true,
    ];

    /**
     * Determine whether this tenant owns shared admin resources.
     */
    public function isDefault(): bool
    {
        return $this->purpose === self::PURPOSE_DEFAULT;
    }

    /**
     * The optional primary contact user for this tenant.
     */
    public function primaryUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_user_id');
    }

    /**
     * All users belonging to this tenant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Attribute casting configuration.
     */
    protected function casts(): array
    {
        return [
            'purpose' => 'string',
            'enabled' => 'boolean',
        ];
    }
}
