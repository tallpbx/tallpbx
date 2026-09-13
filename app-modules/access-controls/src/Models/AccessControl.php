<?php

declare(strict_types=1);

namespace Modules\AccessControls\Models;

use App\Models\Tenant;
use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\AccessControlFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An access control rule defines network-level allow/deny rules
 * with associated nodes (CIDR ranges, domains, or IPs) used by
 * FreeSWITCH's ACL system.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $action
 * @property bool $enabled
 * @property-read Collection<AccessControlNode> $nodes
 */
class AccessControl extends Model
{
    /** @use HasFactory<AccessControlFactory> */
    use BelongsToTenant;

    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'action',
        'enabled',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): AccessControlFactory
    {
        return AccessControlFactory::new();
    }

    /**
     * The CIDR, IP, or domain nodes associated with this rule.
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(AccessControlNode::class)
            ->orderBy('order');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
