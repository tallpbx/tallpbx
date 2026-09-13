<?php

declare(strict_types=1);

namespace Modules\FeatureCodes\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\FeatureCodeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A feature code is a star code (e.g. *97 for voicemail) that triggers
 * a specific action in the dialplan. Each tenant can customize its codes.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property string|null $application FreeSWITCH application to execute (null = log-only)
 * @property string|null $application_data Data for the application
 * @property string|null $description
 * @property bool $enabled
 */
class FeatureCode extends Model
{
    /** @use HasFactory<FeatureCodeFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'application',
        'application_data',
        'description',
        'enabled',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): FeatureCodeFactory
    {
        return FeatureCodeFactory::new();
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
