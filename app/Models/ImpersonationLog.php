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
 * @property int $user_id
 * @property string $action 'start' or 'stop'
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $created_at
 * @property-read Admin $admin
 * @property-read User $user
 */
class ImpersonationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'user_id',
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
}
