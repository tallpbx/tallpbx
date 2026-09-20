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
 * @property bool $enabled
 * @property string $source_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SecurityRule> $rules
 */
class SecurityService extends Model
{
    /**
     * Standard default configuration catalog for system services.
     *
     * @var array<string, array{protocol: string, port_range: string, source_ip: string, description: string}>
     */
    public const DEFAULT_SYSTEM_SERVICES = [
        'ICMP Ping Diagnostics' => [
            'protocol' => 'icmp',
            'port_range' => 'echo-request',
            'source_ip' => 'any',
            'description' => 'Network reachability ping (IPv4 echo-request with burstable rate limit) and essential IPv6 neighbor discovery',
            'rate_limit' => 5,
            'burst' => 5,
        ],
        'SIP Signaling' => [
            'protocol' => 'both',
            'port_range' => '5060,5061,5080',
            'source_ip' => 'any',
            'description' => 'SIP phone registration and call signaling (FreeSWITCH internal and external profiles)',
        ],
        'RTP Voice/Video Media' => [
            'protocol' => 'udp',
            'port_range' => '16384-32768',
            'source_ip' => 'any',
            'description' => 'Audio and video media packet streams',
        ],
        'Web Admin Portal' => [
            'protocol' => 'tcp',
            'port_range' => '80,443',
            'source_ip' => 'any',
            'description' => 'HTTP and HTTPS secure web administrative interface',
        ],
        'SSH Console' => [
            'protocol' => 'tcp',
            'port_range' => '22',
            'source_ip' => 'any',
            'description' => 'Secure Shell host administrative terminal access',
        ],
        'FreeSWITCH ESL' => [
            'protocol' => 'tcp',
            'port_range' => '8021',
            'source_ip' => 'any',
            'description' => 'Event Socket Layer remote control interface',
        ],
        'Reverb WebSockets' => [
            'protocol' => 'tcp',
            'port_range' => '8080',
            'source_ip' => 'any',
            'description' => 'Real-time WebSocket event broadcasting for web panels',
        ],
        'WebRTC WSS' => [
            'protocol' => 'tcp',
            'port_range' => '7443',
            'source_ip' => 'any',
            'description' => 'Secure WebRTC SIP signaling for browser communicators',
        ],
    ];

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
        'enabled',
        'source_ip',
        'rate_limit',
        'burst',
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
            'enabled' => 'boolean',
            'rate_limit' => 'integer',
            'burst' => 'integer',
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
     * Scope query to active (enabled) services only.
     *
     * @param  Builder<SecurityService>  $query
     * @return Builder<SecurityService>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true);
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

    /**
     * Get the factory default configuration for this system service.
     *
     * @return array{protocol: string, port_range: string, source_ip: string, description: string}|null
     */
    public function getDefaultConfig(): ?array
    {
        return self::DEFAULT_SYSTEM_SERVICES[$this->name] ?? null;
    }
}
