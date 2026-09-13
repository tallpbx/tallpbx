<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $parent_id
 * @property string $module_name
 * @property string $key
 * @property string $label
 * @property string|null $route
 * @property string|null $icon
 * @property string $guard
 * @property int $order
 * @property string|null $permission
 * @property bool $enabled
 * @property-read Menu|null $parent
 * @property-read Collection<Menu> $children
 */
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'parent_id',
        'module_name',
        'key',
        'label',
        'route',
        'icon',
        'guard',
        'order',
        'permission',
        'enabled',
    ];

    /**
     * Perform the casts operation.
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'order' => 'integer',
        ];
    }

    /**
     * Perform the parent operation.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Perform the children operation.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    /**
     * Scope the query to a specific guard (admin/web).
     */
    public function scopeGuard($query, string $guard): void
    {
        $query->where('guard', $guard);
    }

    /**
     * Scope to only enabled menu items.
     */
    public function scopeEnabled($query): void
    {
        $query->where('enabled', true);
    }
}
