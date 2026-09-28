<?php

declare(strict_types=1);

namespace Modules\Dialplans\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single condition/action pair within a dialplan. Each detail
 * represents one routing rule evaluated in order.
 *
 * @property string $id
 * @property string $dialplan_id
 * @property string $tag
 * @property string|null $field
 * @property string|null $expression
 * @property string|null $action
 * @property string|null $data
 * @property int $order
 */
class DialplanDetail extends Model
{
    /** @use HasFactory<Modules\Dialplans\Database\Factories\DialplanDetailFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'dialplan_id',
        'tag',
        'field',
        'expression',
        'action',
        'data',
        'order',
    ];

    /**
     * The dialplan this detail row belongs to.
     */
    public function dialplan(): BelongsTo
    {
        return $this->belongsTo(Dialplan::class);
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }
}
