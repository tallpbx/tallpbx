<?php

declare(strict_types=1);

namespace Modules\RingGroups\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\RingGroupFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ring group distributes incoming calls to a set of extensions
 * according to a configured strategy (ring-all, sequential, round-robin).
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string $strategy
 * @property int $ring_timeout
 * @property string|null $description
 * @property bool $enabled
 * @property-read Collection<int, RingGroupExtension> $extensions
 */
class RingGroup extends Model
{
    /** @use HasFactory<RingGroupFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'strategy',
        'ring_timeout',
        'description',
        'enabled',
    ];

    /**
     * Cast model attributes to native types.
     */
    protected function casts(): array
    {
        return [
            'ring_timeout' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * The tenant to which this ring group belongs.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The extensions assigned to this ring group, ordered by ring position.
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(RingGroupExtension::class, 'ring_group_id')->orderBy('position');
    }

    /**
     * Create a new factory instance for model seeding.
     */
    protected static function newFactory(): RingGroupFactory
    {
        return RingGroupFactory::new();
    }
}
