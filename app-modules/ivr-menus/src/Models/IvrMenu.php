<?php

declare(strict_types=1);

namespace Modules\IvrMenus\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\IvrMenuFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\FileStores\Models\MediaAsset;

/**
 * An IVR (Interactive Voice Response) menu accepts caller keypad
 * input and routes the call based on digit-to-action mappings.
 * Each menu belongs to a tenant and can have multiple options
 * (e.g., "press 1 for Sales, press 2 for Support").
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $greeting
 * @property int $timeout
 * @property int $max_failures
 * @property int $digit_length
 * @property string|null $description
 * @property bool $enabled
 * @property-read Collection<int, IvrMenuOption> $options
 */
class IvrMenu extends Model
{
    /** @use HasFactory<IvrMenuFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'greeting',
        'timeout',
        'max_failures',
        'digit_length',
        'description',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'timeout' => 'integer',
            'max_failures' => 'integer',
            'digit_length' => 'integer',
        ];
    }

    /**
     * The tenant to which this IVR menu belongs.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The digit-to-action options configured for this IVR menu.
     */
    public function options(): HasMany
    {
        return $this->hasMany(IvrMenuOption::class, 'ivr_menu_id');
    }

    /** Return the managed local greeting asset. */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }

    protected static function newFactory(): IvrMenuFactory
    {
        return IvrMenuFactory::new();
    }
}
