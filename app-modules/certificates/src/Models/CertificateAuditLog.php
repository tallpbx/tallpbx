<?php

declare(strict_types=1);

namespace Modules\Certificates\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model representing an audit log entry for certificate operations.
 *
 * Tracks administrator actions and scheduled system events (issuance, renewal, deployment, deletion).
 *
 * @property int $id
 * @property int|null $certificate_id
 * @property int|null $admin_id
 * @property string $action
 * @property string $status
 * @property string $message
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Certificate|null $certificate
 * @property-read Admin|null $admin
 */
class CertificateAuditLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'certificate_audit_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'certificate_id',
        'admin_id',
        'action',
        'status',
        'message',
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
     * The certificate this log belongs to.
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'certificate_id');
    }

    /**
     * The admin user who initiated the action (NULL for scheduled system events).
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
