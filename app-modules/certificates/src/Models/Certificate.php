<?php

declare(strict_types=1);

namespace Modules\Certificates\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model representing an SSL/TLS Certificate in TallPBX.
 *
 * Tracks certificate metadata, service deployment flags (Web HTTPS / Telephony SIP TLS),
 * ACME renewal configuration, and filesystem paths.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string $common_name
 * @property array<int, string>|null $san_domains
 * @property string|null $issuer
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property string|null $serial_number
 * @property string|null $fingerprint_sha256
 * @property bool $is_default_web
 * @property bool $is_default_telephony
 * @property string|null $challenge_type
 * @property int|null $dns_credential_id
 * @property bool $auto_renew
 * @property bool $is_staging
 * @property Carbon|null $last_renewed_at
 * @property string|null $last_renew_error
 * @property string $storage_identifier
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CertificateDnsCredential|null $dnsCredential
 * @property-read Collection<int, CertificateAuditLog> $auditLogs
 */
class Certificate extends Model
{
    public const TYPE_LETS_ENCRYPT = 'lets_encrypt';

    public const TYPE_CUSTOM = 'custom';

    public const TYPE_SELF_SIGNED = 'self_signed';

    public const CHALLENGE_HTTP = 'http-01';

    public const CHALLENGE_DNS = 'dns-01';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'certificates';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'common_name',
        'san_domains',
        'issuer',
        'valid_from',
        'valid_to',
        'serial_number',
        'fingerprint_sha256',
        'is_default_web',
        'is_default_telephony',
        'challenge_type',
        'dns_credential_id',
        'auto_renew',
        'is_staging',
        'last_renewed_at',
        'last_renew_error',
        'storage_identifier',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'san_domains' => 'array',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'last_renewed_at' => 'datetime',
            'is_default_web' => 'boolean',
            'is_default_telephony' => 'boolean',
            'auto_renew' => 'boolean',
            'is_staging' => 'boolean',
        ];
    }

    /**
     * DNS credentials used for DNS-01 challenges.
     */
    public function dnsCredential(): BelongsTo
    {
        return $this->belongsTo(CertificateDnsCredential::class, 'dns_credential_id');
    }

    /**
     * Audit logs associated with this certificate.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(CertificateAuditLog::class, 'certificate_id');
    }

    /**
     * Whether the certificate has expired.
     */
    public function isExpired(): bool
    {
        if ($this->valid_to === null) {
            return false;
        }

        return $this->valid_to->isPast();
    }

    /**
     * Whether the certificate is expiring soon within the given number of days.
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if ($this->valid_to === null || $this->isExpired()) {
            return false;
        }

        return now()->diffInDays($this->valid_to) <= $days;
    }

    /**
     * Calculate days remaining until expiration (0 if expired).
     */
    public function daysUntilExpiration(): int
    {
        if ($this->valid_to === null || $this->isExpired()) {
            return 0;
        }

        return (int) max(0, ceil(now()->diffInDays($this->valid_to, false)));
    }

    /**
     * Get days remaining until expiration attribute.
     */
    public function getDaysUntilExpirationAttribute(): int
    {
        return $this->daysUntilExpiration();
    }

    /**
     * Return DaisyUI badge color class for certificate status.
     */
    public function statusBadgeClass(): string
    {
        if ($this->isExpired()) {
            return 'badge-error';
        }

        if ($this->isExpiringSoon(30)) {
            return 'badge-warning';
        }

        return 'badge-success';
    }

    /**
     * Scope to find active web certificate.
     */
    public function scopeActiveWeb(Builder $query): Builder
    {
        return $query->where('is_default_web', true);
    }

    /**
     * Scope to find active telephony certificate.
     */
    public function scopeActiveTelephony(Builder $query): Builder
    {
        return $query->where('is_default_telephony', true);
    }
}
