<?php

declare(strict_types=1);

namespace Modules\XmlCdr\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\CdrFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cdr extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $table = 'xml_cdr';

    protected $fillable = [
        'tenant_id',
        'call_uuid',
        'caller_id',
        'caller_id_name',
        'destination',
        'duration',
        'billsec',
        'hangup_cause',
        'direction',
        'start_stamp',
        'answer_stamp',
        'end_stamp',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'billsec' => 'integer',
            'start_stamp' => 'datetime',
            'answer_stamp' => 'datetime',
            'end_stamp' => 'datetime',
        ];
    }

    protected static function newFactory(): CdrFactory
    {
        return CdrFactory::new();
    }
}
