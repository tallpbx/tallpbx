<?php

declare(strict_types=1);

namespace Modules\Provision\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\ProvisionTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProvisionTemplate extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'vendor', 'model', 'file_path', 'enabled',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    protected static function newFactory(): ProvisionTemplateFactory
    {
        return ProvisionTemplateFactory::new();
    }
}
