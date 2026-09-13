<?php

declare(strict_types=1);

namespace Modules\FileStores\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\MediaAssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;

/**
 * Records the selected destination and lifecycle state for one owner media file.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $file_store_id
 * @property string $owner_type
 * @property string $owner_id
 * @property MediaCategory $category
 * @property MediaAssetStatus $status
 */
class MediaAsset extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * Default lifecycle values used before the model is persisted.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'status' => 'pending',
        'sync_attempts' => 0,
    ];

    /**
     * Attributes that may be assigned by media storage services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'file_store_id',
        'owner_type',
        'owner_id',
        'category',
        'status',
        'object_key',
        'staging_path',
        'original_filename',
        'mime_type',
        'byte_size',
        'sha256',
        'sync_attempts',
        'last_attempt_at',
        'synced_at',
        'alerted_at',
        'last_error',
    ];

    /**
     * Cast lifecycle fields and file metadata to their native values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MediaCategory::class,
            'status' => MediaAssetStatus::class,
            'byte_size' => 'integer',
            'sync_attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'synced_at' => 'datetime',
            'alerted_at' => 'datetime',
        ];
    }

    /**
     * Return the File Store selected for this asset.
     */
    public function fileStore(): BelongsTo
    {
        return $this->belongsTo(FileStore::class);
    }

    /**
     * Return the media-owning feature record.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create the dedicated media asset test factory.
     */
    protected static function newFactory(): MediaAssetFactory
    {
        return MediaAssetFactory::new();
    }
}
