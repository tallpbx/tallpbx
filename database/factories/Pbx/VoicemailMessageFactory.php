<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use Modules\Voicemails\Models\Voicemail;

/**
 * @extends Factory<VoicemailMessage>
 */
class VoicemailMessageFactory extends Factory
{
    protected $model = VoicemailMessage::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'voicemail_id' => Voicemail::factory(),
            'caller_id' => fake()->numerify('+1##########'),
            'caller_id_name' => fake()->name(),
            'duration' => fake()->numberBetween(5, 120),
            'file_path' => 'voicemails/'.fake()->uuid().'.wav',
            'listened' => fake()->boolean(30),
        ];
    }
}
