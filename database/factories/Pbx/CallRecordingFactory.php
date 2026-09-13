<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallRecordings\Models\CallRecording;

/**
 * @extends Factory<CallRecording>
 */
class CallRecordingFactory extends Factory
{
    protected $model = CallRecording::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'caller_id' => fake()->numerify('+1##########'),
            'caller_id_name' => fake()->name(),
            'destination' => fake()->numerify('+1##########'),
            'duration' => fake()->numberBetween(10, 600),
            'file_path' => 'recordings/'.fake()->uuid().'.wav',
            'call_uuid' => fake()->uuid(),
        ];
    }
}
