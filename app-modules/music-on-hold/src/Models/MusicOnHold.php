<?php

declare(strict_types=1);

namespace Modules\MusicOnHold\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\MusicOnHoldFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Modules\FileStores\Models\MediaAsset;

/**
 * Model representing a music on hold audio file or playlist.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $audio_file
 * @property string|null $description
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MusicOnHold extends Model
{
    /** @use HasFactory<MusicOnHoldFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $table = 'music_on_hold';

    protected $fillable = [
        'tenant_id',
        'name',
        'audio_file',
        'description',
        'enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * The tenant to which this music on hold entry belongs.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Return the managed local audio asset. */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }

    protected static function newFactory(): MusicOnHoldFactory
    {
        return MusicOnHoldFactory::new();
    }
}
