<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Model representing one threat feed provider configuration.
 *
 * Stores the sync settings (country filtering, sync interval) and the
 * metadata of the most recent sync attempt. The feed CIDRs themselves never
 * live in MariaDB — they are streamed into the nftables kernel interval
 * sets, so this table stays small no matter how large a feed grows.
 *
 * @property int $id
 * @property string $provider
 * @property string $name
 * @property bool $enabled
 * @property string $country_mode
 * @property array<int, string>|null $countries
 * @property string $sync_interval
 * @property Carbon|null $last_sync_at
 * @property string|null $last_status
 * @property string|null $last_error
 * @property int $entries_count
 * @property string|null $etag
 * @property string|null $last_modified_header
 * @property int $last_rejected_lines
 */
class SecurityThreatFeed extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'security_threat_feeds';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'name',
        'enabled',
        'country_mode',
        'countries',
        'sync_interval',
        'last_sync_at',
        'last_status',
        'last_error',
        'entries_count',
        'etag',
        'last_modified_header',
        'last_rejected_lines',
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
            'entries_count' => 'integer',
            'last_rejected_lines' => 'integer',
        ];
    }

    /**
     * The configured sync interval expressed in seconds.
     */
    public function syncIntervalSeconds(): int
    {
        return match ($this->sync_interval) {
            'hourly' => 3600,
            '4_hours' => 14400,
            '12_hours' => 43200,
            'daily' => 86400,
            default => 86400,
        };
    }

    /**
     * Whether the feed has gone stale: two full sync intervals have passed
     * since the last sync attempt.
     *
     * A feed that has never synced yet is idle, not rotting — a freshly
     * configured feed must never alert before its first run.
     */
    public function isStale(): bool
    {
        if ($this->last_sync_at === null) {
            return false;
        }

        // A feed silently rotting is more dangerous than one that fails
        // loudly, so the threshold is two whole missed intervals.
        return $this->last_sync_at->lt(now()->subSeconds(2 * $this->syncIntervalSeconds()));
    }
}
