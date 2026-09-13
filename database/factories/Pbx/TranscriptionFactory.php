<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Transcribe\Models\Transcription;
use Modules\VoicemailMessages\Models\VoicemailMessage;

/**
 * Generate fake Transcription records for testing.
 *
 * Creates speech-to-text results with random text, confidence scores, and language codes.
 */
class TranscriptionFactory extends Factory
{
    protected $model = Transcription::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'voicemail_message_id' => VoicemailMessage::factory(),
            'text' => fake()->sentence(10),
            'confidence' => fake()->randomFloat(2, 0.5, 0.99),
            'language' => 'en-US',
        ];
    }
}
