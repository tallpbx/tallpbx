<?php

declare(strict_types=1);

namespace Modules\Certificates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model representing third-party DNS provider API credentials.
 *
 * Stores credentials (such as Cloudflare API tokens) encrypted at rest,
 * enabling automated DNS-01 ACME challenges and wildcard SSL certificate issuance.
 *
 * @property int $id
 * @property string $name
 * @property string $provider
 * @property array<string, mixed> $credentials
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CertificateDnsCredential extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'certificate_dns_credentials';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'provider',
        'credentials',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    /**
     * Certificates using this DNS credential.
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'dns_credential_id');
    }
}
