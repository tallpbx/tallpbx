<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model representing an active or historical IP ban.
 *
 * Tracks attacker IP address, vector (web_auth, sip_auth, ssh, manual),
 * ban creation, expiration timestamp, active status, and administrator unban audit.
 *
 * @property int $id
 * @property string $ip_address
 * @property string $vector
 * @property string $reason
 * @property int $attempt_count
 * @property Carbon $banned_at
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property Carbon|null $unbanned_at
 * @property int|null $unbanned_by_admin_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Admin|null $unbannedByAdmin
 */
class SecurityBan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'security_bans';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ip_address',
        'vector',
        'reason',
        'attempt_count',
        'banned_at',
        'expires_at',
        'is_active',
        'unbanned_at',
        'unbanned_by_admin_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'banned_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'unbanned_at' => 'datetime',
        ];
    }

    /**
     * Get the administrator who manually lifted this ban, if applicable.
     *
     * @return BelongsTo<Admin, $this>
     */
    public function unbannedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'unbanned_by_admin_id');
    }

    /**
     * Scope query to currently active bans that have not expired.
     *
     * @param  Builder<SecurityBan>  $query
     * @return Builder<SecurityBan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope query to permanent bans (where expires_at is NULL).
     *
     * @param  Builder<SecurityBan>  $query
     * @return Builder<SecurityBan>
     */
    public function scopePermanent(Builder $query): Builder
    {
        return $query->whereNull('expires_at');
    }

    /**
     * Check if this ban has passed its expiration time.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Return remaining ban duration in seconds, or null if permanent or already expired.
     */
    public function timeRemaining(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        $remaining = now()->diffInSeconds($this->expires_at, false);

        return $remaining > 0 ? (int) $remaining : 0;
    }
}
