<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group is a collection of users that share a set of permissions.
 *
 * System groups (tenant_id = null) apply across all tenants.
 * Tenant-scoped groups (tenant_id set) apply only within that tenant.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property int|null $tenant_id
 * @property-read Tenant|null $tenant
 * @property-read Collection<User> $users
 * @property-read Collection<Permission> $permissions
 */
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'description',
        'tenant_id',
    ];

    /**
     * The tenant this group belongs to. Null for system-wide groups.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Users that belong to this group.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_user')
            ->withTimestamps();
    }

    /**
     * Permissions assigned to this group.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'group_permission');
    }

    /**
     * Scope to system groups (no tenant) only.
     */
    public function scopeSystem($query)
    {
        return $query->whereNull('tenant_id');
    }

    /**
     * Scope to groups belonging to a specific tenant.
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Determine if this is a system-level group.
     */
    public function isSystem(): bool
    {
        return $this->tenant_id === null;
    }
}
