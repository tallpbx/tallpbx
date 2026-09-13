<?php

declare(strict_types=1);

namespace Modules\FileStores\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A system-owned profile for a local or remote file destination.
 *
 * Provider settings are encrypted at rest and excluded from array and JSON
 * serialization so credentials cannot leak through model responses.
 */
class FileStore extends Model
{
    use HasUuids;

    /**
     * Provider setting keys that must never be displayed after persistence.
     *
     * @var list<string>
     */
    public const SensitiveSettings = [
        'access_key',
        'secret',
        'password',
        'private_key',
        'passphrase',
        'access_token',
    ];

    /**
     * Attributes that may be assigned in a destination profile.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'provider',
        'settings',
    ];

    /**
     * Credentials and destination settings must not serialize with the model.
     *
     * @var list<string>
     */
    protected $hidden = [
        'settings',
    ];

    /**
     * Cast settings as an encrypted associative array.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'encrypted:array',
        ];
    }
}
