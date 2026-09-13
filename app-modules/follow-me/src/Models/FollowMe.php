<?php

declare(strict_types=1);

namespace Modules\FollowMe\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\FollowMeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model representing a follow-me call forwarding rule.
 *
 * Each follow-me record belongs to a tenant and defines an
 * extension that should forward unanswered calls to a
 * destination number.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to the owning tenant
 * @property string $name Display name for this follow-me rule
 * @property string $extension The extension number being forwarded
 * @property string $destination The destination to forward calls to
 * @property int $ring_timeout Seconds to ring before forwarding
 * @property bool $enabled Whether this rule is active
 */
class FollowMe extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'follow_me';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'extension',
        'destination',
        'ring_timeout',
        'enabled',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ring_timeout' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): FollowMeFactory
    {
        return FollowMeFactory::new();
    }
}
