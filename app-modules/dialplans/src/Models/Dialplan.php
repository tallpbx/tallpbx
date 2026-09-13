<?php

declare(strict_types=1);

namespace Modules\Dialplans\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\DialplanFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dialplan defines a call routing plan — a collection of ordered
 * conditions and actions evaluated by FreeSWITCH to route calls.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $context
 * @property int $order
 * @property bool $enabled
 * @property-read Collection<DialplanDetail> $details
 */
class Dialplan extends Model
{
    /** @use HasFactory<DialplanFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'context',
        'order',
        'enabled',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): DialplanFactory
    {
        return DialplanFactory::new();
    }

    /**
     * The ordered conditions/actions that make up this dialplan.
     */
    public function details(): HasMany
    {
        return $this->hasMany(DialplanDetail::class)
            ->orderBy('order');
    }

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
