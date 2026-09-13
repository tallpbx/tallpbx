<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\TenantManager;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
/**
 * A tenant user belonging to one or more tenants.
 *
 * Users are scoped to tenants and can belong to multiple tenants
 * with different roles. They access the portal application.
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'theme',
        'layout_mode',
        'sidebar_collapsed',
        'language',
        'enabled',
    ];

    /**
     * Tenants this user belongs to.
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot(['role', 'primary'])
            ->withTimestamps();
    }

    /**
     * Tenants this user owns or created.
     */
    public function ownedTenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'primary_user_id');
    }

    /**
     * Groups that this user belongs to.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_user')
            ->withTimestamps();
    }

    /**
     * Check if the user belongs to the given tenant.
     */
    public function isInTenant(int $tenantId): bool
    {
        return $this->tenants()->where('tenants.id', $tenantId)->exists();
    }

    /**
     * In-memory cache of permission names for the active tenant.
     *
     * @var array<int, string>|null
     */
    private ?array $cachedPermissions = null;

    /**
     * Tenant ID represented by the current permission cache.
     */
    private ?string $cachedPermissionsTenantId = null;

    /**
     * Load permission names for system groups and the active tenant's groups.
     *
     * Tenant users can never receive global admin permissions, even if a
     * malformed or compromised group assignment contains an admin.* ability.
     *
     * @return array<int, string>
     */
    public function getPermissionNames(): array
    {
        $tenantId = app(TenantManager::class)->getTenantId();

        if ($tenantId === null) {
            return [];
        }

        if ($this->cachedPermissions === null || $this->cachedPermissionsTenantId !== $tenantId) {
            $this->cachedPermissions = $this->groups()
                ->where(function ($query) use ($tenantId): void {
                    $query->whereNull('groups.tenant_id')
                        ->orWhere('groups.tenant_id', (int) $tenantId);
                })
                ->with(['permissions' => function ($query): void {
                    $query->where('name', 'not like', 'admin.%');
                }])
                ->get()
                ->pluck('permissions')
                ->flatten()
                ->pluck('name')
                ->unique()
                ->toArray();
            $this->cachedPermissionsTenantId = $tenantId;
        }

        return $this->cachedPermissions;
    }

    /**
     * Check if this user has a specific permission via their groups.
     *
     * Uses the in-memory cache from getPermissionNames() to avoid
     * repeated database queries during Gate authorization.
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->getPermissionNames(), true);
    }

    /**
     * Attribute casting configuration.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'enabled' => 'boolean',
            'sidebar_collapsed' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's preferred locale for translations and emails.
     *
     * Used by Laravel's HasLocalePreference contract to determine
     * which language to use for notifications and mail.
     */
    public function preferredLocale(): string
    {
        return $this->language ?? config('app.locale');
    }
}
