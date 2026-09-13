<?php

declare(strict_types=1);

namespace Modules\AccessControls\Models;

use Database\Factories\Pbx\AccessControlNodeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single node (CIDR range, IP address, or domain) within an access
 * control rule. Each node contributes to the allow/deny decision.
 *
 * @property string $id
 * @property string $access_control_id
 * @property string $type
 * @property string $value
 * @property int $order
 */
class AccessControlNode extends Model
{
    /** @use HasFactory<AccessControlNodeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'access_control_id',
        'type',
        'value',
        'order',
    ];

    public function accessControl(): BelongsTo
    {
        return $this->belongsTo(AccessControl::class);
    }

    protected static function newFactory(): AccessControlNodeFactory
    {
        return AccessControlNodeFactory::new();
    }

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
