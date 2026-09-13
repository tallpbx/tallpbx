<?php

declare(strict_types=1);

namespace Modules\RingGroups\Models;

use Database\Factories\Pbx\RingGroupExtensionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Extensions\Models\Extension;

/**
 * An extension assigned to a ring group with a specific ring position.
 *
 * The extension_uuid foreign key references the Extension model ID
 * (UUID primary key in the extensions table).
 *
 * @property string $id
 * @property string $ring_group_id
 * @property string $extension_uuid
 * @property int $position
 * @property-read Extension $extension The associated extension record
 */
class RingGroupExtension extends Model
{
    /** @use HasFactory<RingGroupExtensionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'ring_group_id',
        'extension_uuid',
        'position',
    ];

    /**
     * Cast model attributes to native types.
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * The ring group that this extension belongs to.
     */
    public function ringGroup(): BelongsTo
    {
        return $this->belongsTo(RingGroup::class);
    }

    /**
     * The Extension model referenced by extension_uuid.
     *
     * Used to resolve the dialable extension number for
     * ring group bridge strings in generated dialplan XML.
     */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid');
    }

    /**
     * Create a new factory instance for model seeding.
     */
    protected static function newFactory(): RingGroupExtensionFactory
    {
        return RingGroupExtensionFactory::new();
    }
}
