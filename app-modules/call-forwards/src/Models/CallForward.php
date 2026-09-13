<?php

declare(strict_types=1);

namespace Modules\CallForwards\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\CallForwardFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call forwarding rule for a specific extension and forward type.
 * Each extension can have one forward rule per type (unconditional,
 * busy, no-answer, not-found).
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $extension_uuid
 * @property string $forward_type
 * @property string $destination
 * @property int $ring_timeout
 * @property bool $enabled
 */
class CallForward extends Model
{
    /** @use HasFactory<CallForwardFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'extension_uuid',
        'forward_type',
        'destination',
        'ring_timeout',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'ring_timeout' => 'integer',
        ];
    }

    /**
     * The tenant to which this call forward rule belongs.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): CallForwardFactory
    {
        return CallForwardFactory::new();
    }
}
