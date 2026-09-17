<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Model representing an entry in the trusted (whitelist) or blocked (blacklist) IP list.
 *
 * Whitelisted IPs are unconditionally permitted and immune to intrusion bans.
 * Blacklisted IPs are dropped immediately at kernel network ingress.
 *
 * @property int $id
 * @property string $type
 * @property string $ip_address
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SecurityIpList extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'security_ip_lists';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'ip_address',
        'description',
    ];

    /**
     * Scope query to trusted whitelist entries only.
     *
     * @param  Builder<SecurityIpList>  $query
     * @return Builder<SecurityIpList>
     */
    public function scopeWhitelist(Builder $query): Builder
    {
        return $query->where('type', 'whitelist');
    }

    /**
     * Scope query to blocked blacklist entries only.
     *
     * @param  Builder<SecurityIpList>  $query
     * @return Builder<SecurityIpList>
     */
    public function scopeBlacklist(Builder $query): Builder
    {
        return $query->where('type', 'blacklist');
    }

    /**
     * Determine if this entry is a trusted whitelist record.
     */
    public function isWhitelist(): bool
    {
        return $this->type === 'whitelist';
    }

    /**
     * Determine if this entry is a blocked blacklist record.
     */
    public function isBlacklist(): bool
    {
        return $this->type === 'blacklist';
    }
}
