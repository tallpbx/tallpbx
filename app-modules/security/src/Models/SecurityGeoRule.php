<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Model representing the dedicated Geo-Blocking rule configuration.
 *
 * @property int $id
 * @property bool $enabled
 * @property string $mode
 * @property string $port_scope
 * @property array<int, string>|null $countries
 * @property string $sync_interval
 * @property Carbon|null $last_sync_at
 * @property string|null $last_status
 * @property string|null $last_error
 * @property int $v4_cidrs_count
 * @property int $v6_cidrs_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SecurityGeoRule extends Model
{
    use HasFactory;

    protected $table = 'security_geo_rules';

    protected $fillable = [
        'enabled',
        'mode',
        'port_scope',
        'countries',
        'sync_interval',
        'last_sync_at',
        'last_status',
        'last_error',
        'v4_cidrs_count',
        'v6_cidrs_count',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'countries' => 'array',
            'last_sync_at' => 'datetime',
            'v4_cidrs_count' => 'integer',
            'v6_cidrs_count' => 'integer',
        ];
    }

    /**
     * Get or create the singleton instance of the Geo-Blocking rule configuration.
     */
    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'enabled' => false,
            'mode' => 'blacklist',
            'port_scope' => 'all_traffic',
            'countries' => [],
            'sync_interval' => 'weekly',
            'v4_cidrs_count' => 0,
            'v6_cidrs_count' => 0,
        ]);
    }

    /**
     * Return total compiled CIDRs across IPv4 and IPv6.
     */
    public function totalCidrsCount(): int
    {
        return $this->v4_cidrs_count + $this->v6_cidrs_count;
    }

    /**
     * The configured sync interval expressed in seconds.
     */
    public function syncIntervalSeconds(): int
    {
        return match ($this->sync_interval) {
            'monthly' => 30 * 86400,
            'on_demand' => PHP_INT_MAX,
            default => 7 * 86400, // 'weekly'
        };
    }

    /**
     * Whether the geo-ip rule is due for automated refresh based on its interval.
     */
    public function isDue(): bool
    {
        if ($this->sync_interval === 'on_demand') {
            return false;
        }

        if ($this->last_sync_at === null || $this->last_status === 'failed') {
            return true;
        }

        return $this->last_sync_at->addSeconds($this->syncIntervalSeconds())->lte(now());
    }
}
