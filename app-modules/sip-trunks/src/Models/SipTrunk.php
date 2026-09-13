<?php

declare(strict_types=1);

namespace Modules\SipTrunks\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\SipTrunkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a SIP trunk connection to a remote carrier.
 *
 * Stores host, port, authentication credentials, and enabled codecs.
 */
class SipTrunk extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'host', 'port', 'username', 'password', 'codecs', 'enabled',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): SipTrunkFactory
    {
        return SipTrunkFactory::new();
    }
}
