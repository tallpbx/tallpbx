<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\PermissionService;
use Database\Factories\AdminFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
/**
 * An administrative user with system-wide access.
 *
 * Admins are separate from tenant users. They manage the system
 * via the admin panel and inherit permissions through groups.
 */
class Admin extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'theme',
        'layout_mode',
        'sidebar_collapsed',
        'enabled',
        'language',
    ];

    /**
     * Groups that this admin belongs to.
     *
     * Admins inherit permissions from their groups,
     * enabling features like impersonation.
     *
     * @return BelongsToMany<Group>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'admin_group')
            ->withTimestamps();
    }

    /**
     * Determine whether this administrator belongs to the system-level Super Administrators group.
     */
    public function isSuperAdmin(): bool
    {
        if ($this->relationLoaded('groups')) {
            return $this->groups->contains(
                fn (Group $group) => $group->name === 'Super Administrators' && $group->tenant_id === null
            );
        }

        return $this->groups()
            ->where('name', 'Super Administrators')
            ->whereNull('tenant_id')
            ->exists();
    }

    /**
     * In-memory cache of permission names, loaded once per request.
     *
     * @var array<int, string>|null
     */
    private ?array $cachedPermissions = null;

    /**
     * Load all permission names via eager-loaded groups, cached in-memory.
     *
     * Super Administrators possess all registered module permissions.
     * Other administrators inherit permissions assigned to their groups.
     *
     * @return array<int, string>
     */
    public function getPermissionNames(): array
    {
        if ($this->cachedPermissions === null) {
            if ($this->isSuperAdmin() && app()->bound(PermissionService::class)) {
                $registered = app(PermissionService::class)->all();

                if (! empty($registered)) {
                    return $this->cachedPermissions = $registered;
                }
            }

            $this->cachedPermissions = $this->groups()
                ->with('permissions')
                ->get()
                ->pluck('permissions')
                ->flatten()
                ->pluck('name')
                ->unique()
                ->toArray();
        }

        return $this->cachedPermissions;
    }

    /**
     * Check if this admin has a specific permission via their groups.
     *
     * Super Administrators possess all registered system permissions.
     * Other administrators inherit permissions assigned to their groups.
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
            'password' => 'hashed',
            'enabled' => 'boolean',
            'sidebar_collapsed' => 'boolean',
        ];
    }

    /**
     * Get the admin's preferred locale for translations and emails.
     *
     * Used by Laravel's HasLocalePreference contract to determine
     * which language to use for notifications and mail.
     */
    public function preferredLocale(): string
    {
        return $this->language ?? config('app.locale');
    }
}
