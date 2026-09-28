<?php

declare(strict_types=1);

namespace Modules\NumberTranslations\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores number translation rules for call manipulation.
 *
 * Each rule defines a regex match pattern and replacement for inbound, outbound, or both directions.
 */
class NumberTranslation extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'match_pattern', 'replace_pattern', 'direction', 'enabled', 'order',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
