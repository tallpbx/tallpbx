<?php

declare(strict_types=1);

namespace Modules\CallFlows\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\CallFlowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string $name
 * @property string $extension
 * @property string|null $destination_type
 * @property string|null $destination_id
 * @property bool $enabled
 */
class CallFlow extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'extension',
        'destination_type',
        'destination_id',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): CallFlowFactory
    {
        return CallFlowFactory::new();
    }
}
