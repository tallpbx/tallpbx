<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\PermissionService;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A permission represents a single action a user can perform.
 *
 * Permissions are registered by modules at boot time and synchronized
 * to the database. They are assigned to groups via the group_permission
 * pivot table.
 *
 * @property string $id
 * @property string $name
 * @property string $module
 * @property string|null $description
 * @property-read Collection<Group> $groups
 */
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'module',
        'description',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saved(function () {
            if (app()->bound(PermissionService::class)) {
                app(PermissionService::class)->clearMemoization();
            }
        });

        static::deleted(function () {
            if (app()->bound(PermissionService::class)) {
                app(PermissionService::class)->clearMemoization();
            }
        });
    }

    /**
     * Groups that have this permission.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_permission');
    }
}
