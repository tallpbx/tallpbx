<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model representing a network service in the PBX Port Catalog.
 *
 * Used to catalog well-known PBX ports (SIP, RTP, Web Admin, SSH, etc.)
 * as well as custom administrator-defined services.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $protocol
 * @property string $port_range
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SecurityRule> $rules
 */
class SecurityService extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'security_services';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'protocol',
        'port_range',
        'is_system',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Get all sequential firewall rules that reference this service.
     *
     * @return HasMany<SecurityRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(SecurityRule::class, 'service_id');
    }

    /**
     * Scope query to standard system services only.
     *
     * @param  Builder<SecurityService>  $query
     * @return Builder<SecurityService>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope query to user-defined custom services only.
     *
     * @param  Builder<SecurityService>  $query
     * @return Builder<SecurityService>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }
}
