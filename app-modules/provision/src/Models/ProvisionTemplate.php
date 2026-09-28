<?php

declare(strict_types=1);

namespace Modules\Provision\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\ProvisionTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A device provisioning template owned by a tenant.
 */
class ProvisionTemplate extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'vendor', 'model', 'file_path', 'enabled',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): ProvisionTemplateFactory
    {
        return ProvisionTemplateFactory::new();
    }
}
