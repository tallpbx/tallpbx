<?php

declare(strict_types=1);

namespace Modules\Fax\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\FileStores\Models\MediaAsset;

class FaxOutgoing extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'fax_queue';

    protected $fillable = [
        'tenant_id',
        'fax_number',
        'document_path',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Return the managed local spool or completed archive for this fax.
     */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->withoutGlobalScope('tenant');
    }
}
