<?php

declare(strict_types=1);

namespace Modules\Speech\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\Pbx\SpeechConfigFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores text-to-speech (TTS) engine configuration per tenant.
 *
 * Defines which TTS engine to use (e.g. Google, Amazon, Microsoft),
 * the voice, language, and speech rate. Each tenant can have
 * multiple configs for different use cases.
 *
 * @property string $id UUID primary key
 * @property int $tenant_id
 * @property string $engine The TTS provider name
 * @property string|null $voice Voice identifier (e.g. en-US-Standard-A)
 * @property string|null $language Language code (e.g. en-US)
 * @property float $rate Speech rate (0.1-3.0, default 1.0)
 * @property bool $enabled Whether this config is active
 */
class SpeechConfig extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'speech_config';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id', 'engine', 'voice', 'language', 'rate', 'enabled',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Create a new factory instance for this model.
     */
    protected static function newFactory(): SpeechConfigFactory
    {
        return SpeechConfigFactory::new();
    }
}
