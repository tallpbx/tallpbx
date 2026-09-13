<?php

declare(strict_types=1);

namespace Modules\ConferenceCenters\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\ConferenceCenterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\FileStores\Models\MediaAsset;

/**
 * Eloquent model representing a conference center dial-in service.
 *
 * Each conference center belongs to a tenant and provides a
 * dial-in extension that callers use to join conference rooms.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to the owning tenant
 * @property string $name Display name for this conference center
 * @property string $extension Extension number to dial in
 * @property string|null $pin Optional PIN for the conference center
 * @property string|null $greeting Optional greeting audio file path
 * @property bool $enabled Whether this conference center is active
 */
class ConferenceCenter extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'extension',
        'pin',
        'greeting',
        'enabled',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /** Return the managed local greeting asset. */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }

    /**
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): ConferenceCenterFactory
    {
        return ConferenceCenterFactory::new();
    }
}
