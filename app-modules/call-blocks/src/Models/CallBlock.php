<?php

declare(strict_types=1);

namespace Modules\CallBlocks\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\CallBlockFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call block rule that blocks incoming calls matching a
 * caller ID number pattern.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string $caller_id_number
 * @property string|null $description
 * @property bool $enabled
 */
class CallBlock extends Model
{
    /** @use HasFactory<CallBlockFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'caller_id_number',
        'description',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * The tenant to which this call block rule belongs.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): CallBlockFactory
    {
        return CallBlockFactory::new();
    }
}
