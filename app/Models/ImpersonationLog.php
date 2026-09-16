<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log entry for admin impersonation actions.
 *
 * Records every start and stop of an impersonation session
 * with the admin who initiated it, the target user, and
 * request metadata for security auditing.
 *
 * @property int $id
 * @property int $admin_id
 * @property string|null $admin_name
 * @property int $user_id
 * @property string|null $user_email
 * @property string $action 'start' or 'stop'
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $created_at
 * @property-read Admin $admin
 * @property-read User $user
 * @property-read string $display_admin
 * @property-read string $display_user
 */
class ImpersonationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'admin_name',
        'user_id',
        'user_email',
        'action',
        'ip_address',
        'user_agent',
    ];

    /**
     * The admin who initiated this impersonation action.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * The target user of this impersonation action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return the human-readable admin name, using snapshot or relationship.
     */
    public function getDisplayAdminAttribute(): string
    {
        if (! empty($this->admin_name)) {
            return $this->admin_name;
        }

        return $this->admin?->name ?? 'Admin #'.$this->admin_id;
    }

    /**
     * Return the human-readable user identifier, using snapshot or relationship.
     */
    public function getDisplayUserAttribute(): string
    {
        if (! empty($this->user_email)) {
            return $this->user_email;
        }

        return $this->user?->email ?? $this->user?->name ?? 'User #'.$this->user_id;
    }
}
