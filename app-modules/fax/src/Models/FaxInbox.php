<?php

declare(strict_types=1);

namespace Modules\Fax\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\FaxInboxFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\FileStores\Models\MediaAsset;

class FaxInbox extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $table = 'fax_inbox';

    protected $fillable = [
        'tenant_id',
        'caller_id',
        'pages',
        'document_path',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'pages' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    protected static function newFactory(): FaxInboxFactory
    {
        return FaxInboxFactory::new();
    }

    /**
     * Return the managed archive for a received fax document.
     */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }
}
