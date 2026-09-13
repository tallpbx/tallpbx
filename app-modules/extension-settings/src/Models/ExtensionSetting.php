<?php

declare(strict_types=1);

namespace Modules\ExtensionSettings\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\ExtensionSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Extensions\Models\Extension;

/**
 * Stores per-extension feature override settings.
 *
 * Key-value settings linked to an extension, e.g. call waiting, DND, caller ID.
 */
class ExtensionSetting extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'extension_id', 'key', 'value',
    ];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    protected static function newFactory(): ExtensionSettingFactory
    {
        return ExtensionSettingFactory::new();
    }
}
