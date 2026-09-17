<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Model representing an enterprise audit log entry for security operations.
 *
 * Tracks administrative actions such as rule mutations, manual bans, unbans,
 * whitelist/blacklist edits, and protection threshold updates.
 *
 * @property int $id
 * @property int|null $admin_id
 * @property string $action
 * @property string|null $ip_address
 * @property string|null $description
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Admin|null $admin
 */
class SecurityAuditLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'security_audit_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admin_id',
        'action',
        'ip_address',
        'description',
        'details',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    /**
     * Get the administrator who performed this action, if authenticated.
     *
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * Record a security audit log entry.
     *
     * Automatically resolves the current authenticated admin ID if not explicitly passed.
     *
     * @param  string  $action  Event name (e.g. 'ban_created', 'unban_executed', 'rule_saved')
     * @param  string|null  $ipAddress  Target IP address if applicable
     * @param  string|null  $description  Human-readable summary of the action
     * @param  array<string, mixed>|null  $details  Additional structured context
     * @param  int|null  $adminId  Explicit admin ID override
     */
    public static function record(
        string $action,
        ?string $ipAddress = null,
        ?string $description = null,
        ?array $details = null,
        ?int $adminId = null,
    ): self {
        $effectiveAdminId = $adminId ?? (Auth::guard('admin')->check() ? Auth::guard('admin')->id() : null);

        return static::create([
            'admin_id' => $effectiveAdminId,
            'action' => $action,
            'ip_address' => $ipAddress,
            'description' => $description,
            'details' => $details,
        ]);
    }
}
