<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Speech\Models\SpeechConfig;

/**
 * Generate fake SpeechConfig records for testing.
 *
 * Creates TTS engine configurations with random provider, voice, and language settings.
 */
class SpeechConfigFactory extends Factory
{
    protected $model = SpeechConfig::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'engine' => fake()->randomElement(['google', 'amazon', 'microsoft']),
            'voice' => 'en-US-Standard-'.fake()->randomLetter(),
            'language' => 'en-US',
            'rate' => 1.0,
            'enabled' => true,
        ];
    }
}
