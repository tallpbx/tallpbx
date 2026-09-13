<?php

declare(strict_types=1);

namespace Modules\EmailTemplates\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores email notification templates per tenant.
 *
 * Each template has a name, subject line, and body content for outbound notifications.
 */
class EmailTemplate extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'subject', 'body',
    ];

    protected static function newFactory(): EmailTemplateFactory
    {
        return EmailTemplateFactory::new();
    }
}
