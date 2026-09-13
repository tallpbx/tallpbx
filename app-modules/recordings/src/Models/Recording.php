<?php

declare(strict_types=1);

namespace Modules\Recordings\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\RecordingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\FileStores\Models\MediaAsset;

/**
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string $name
 * @property string $type
 * @property string $file_path
 * @property int $duration
 * @property bool $enabled
 */
class Recording extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'file_path',
        'duration',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): RecordingFactory
    {
        return RecordingFactory::new();
    }

    /**
     * Return the managed local media asset for a newly uploaded recording.
     */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }
}
