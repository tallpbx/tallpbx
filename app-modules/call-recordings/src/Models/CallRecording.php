<?php

declare(strict_types=1);

namespace Modules\CallRecordings\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\CallRecordingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\FileStores\Models\MediaAsset;

/**
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string|null $caller_id
 * @property string|null $caller_id_name
 * @property string|null $destination
 * @property int $duration
 * @property string $file_path
 * @property string|null $call_uuid
 */
class CallRecording extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'caller_id',
        'caller_id_name',
        'destination',
        'duration',
        'file_path',
        'call_uuid',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
        ];
    }

    protected static function newFactory(): CallRecordingFactory
    {
        return CallRecordingFactory::new();
    }

    /**
     * Return the managed media asset for newly completed recordings.
     */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }
}
