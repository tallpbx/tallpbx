<?php

declare(strict_types=1);

namespace Modules\HotDesking\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\HotDeskSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Devices\Models\Device;
use Modules\Extensions\Models\Extension;

/**
 * Represents a hot desking session linking a user extension
 * to a physical desk phone extension and device.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $extension_id
 * @property string $device_extension_id
 * @property string|null $device_id
 * @property string|null $device_mac
 * @property string|null $ip_address
 * @property bool $is_active
 * @property Carbon $login_at
 * @property Carbon|null $logout_at
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HotDeskSession extends Model
{
    /** @use HasFactory<HotDeskSessionFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasUuids;

    protected static function newFactory(): HotDeskSessionFactory
    {
        return HotDeskSessionFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'extension_id',
        'device_extension_id',
        'device_id',
        'device_mac',
        'ip_address',
        'is_active',
        'login_at',
        'logout_at',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
        ];
    }

    /**
     * Tenant to which this hot desking session belongs.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The visiting user's primary extension.
     */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_id')->withoutGlobalScope('tenant');
    }

    /**
     * The physical desk phone's base extension.
     */
    public function deviceExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'device_extension_id')->withoutGlobalScope('tenant');
    }

    /**
     * The physical device hardware record, if associated.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id')->withoutGlobalScope('tenant');
    }

    /**
     * Scope query to only active sessions.
     *
     * @param  Builder<HotDeskSession>  $query
     * @return Builder<HotDeskSession>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
