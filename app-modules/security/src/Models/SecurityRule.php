<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model representing a sequential host firewall filtering rule.
 *
 * Rules are evaluated in sequential order (10, 20, 30...) with first-match-wins logic.
 *
 * @property int $id
 * @property int $sequence
 * @property string $description
 * @property string $source_ip
 * @property int|null $service_id
 * @property string|null $custom_port
 * @property string|null $custom_protocol
 * @property string $action
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SecurityService|null $service
 */
class SecurityRule extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'security_rules';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sequence',
        'description',
        'source_ip',
        'service_id',
        'custom_port',
        'custom_protocol',
        'action',
        'enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Get the cataloged network service associated with this rule, if any.
     *
     * @return BelongsTo<SecurityService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(SecurityService::class, 'service_id');
    }

    /**
     * Scope query to order rules by their evaluation sequence.
     *
     * @param  Builder<SecurityRule>  $query
     * @return Builder<SecurityRule>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sequence', 'asc')->orderBy('id', 'asc');
    }

    /**
     * Scope query to enabled rules only.
     *
     * @param  Builder<SecurityRule>  $query
     * @return Builder<SecurityRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
