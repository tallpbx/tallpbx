<?php

declare(strict_types=1);

namespace Modules\EmailQueue\Models;

use App\Traits\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\Pbx\EmailQueueItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a single email in the outbound message queue.
 *
 * Tracks delivery status (pending/sent/failed) and stores the
 * full email content so it can be retried or inspected by admins.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string $to The recipient email address
 * @property string $subject The email subject line
 * @property string $body The email body content
 * @property string $status pending|sent|failed
 * @property Carbon|null $sent_at When the email was successfully sent
 */
class EmailQueueItem extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'email_queue';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id', 'to', 'subject', 'body', 'status', 'sent_at',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /**
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): EmailQueueItemFactory
    {
        return EmailQueueItemFactory::new();
    }
}
