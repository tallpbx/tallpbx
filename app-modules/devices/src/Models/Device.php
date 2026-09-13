<?php

declare(strict_types=1);

namespace Modules\Devices\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\DeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SipAccounts\Models\SipAccount;

/**
 * A device represents a physical or soft phone registered in the system.
 * Records vendor, model, globally-unique MAC address, and the provisioning
 * template to use.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $vendor
 * @property string|null $model
 * @property string $mac_address
 * @property string|null $template
 * @property string|null $sip_account_id
 * @property array|null $settings
 * @property bool $enabled
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'vendor',
        'model',
        'mac_address',
        'template',
        'sip_account_id',
        'settings',
        'enabled',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The SIP account whose credentials this device registers with.
     *
     * Bypasses the tenant scope: this relation is loaded from the public
     * provisioning endpoint and admin panels where no tenant context exists.
     */
    public function sipAccount(): BelongsTo
    {
        return $this->belongsTo(SipAccount::class)->withoutGlobalScope('tenant');
    }

    protected static function newFactory(): DeviceFactory
    {
        return DeviceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
