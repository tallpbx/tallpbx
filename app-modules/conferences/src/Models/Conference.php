<?php

declare(strict_types=1);

namespace Modules\Conferences\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\ConferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model representing a conference room.
 *
 * Each conference belongs to a tenant and defines a FreeSWITCH
 * conference bridge with a profile, optional PIN, and member limit.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id Foreign key to the owning tenant
 * @property string $name Display name for the conference
 * @property string $profile FreeSWITCH conference profile name
 * @property string|null $pin Optional PIN for caller authentication
 * @property int $max_members Maximum simultaneous participants
 * @property bool $enabled Whether this conference is active
 */
class Conference extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'profile',
        'pin',
        'max_members',
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
            'max_members' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): ConferenceFactory
    {
        return ConferenceFactory::new();
    }
}
