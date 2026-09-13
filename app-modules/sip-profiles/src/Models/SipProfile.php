<?php

declare(strict_types=1);

namespace Modules\SipProfiles\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\SipProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A SIP profile defines a FreeSWITCH Sofia profile — internal, external,
 * or custom — controlling IP/port bindings and SIP protocol behavior.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property array|null $settings
 * @property bool $enabled
 */
class SipProfile extends Model
{
    /** @use HasFactory<SipProfileFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $attributes = [
        'enabled' => true,
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'settings',
        'enabled',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): SipProfileFactory
    {
        return SipProfileFactory::new();
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
