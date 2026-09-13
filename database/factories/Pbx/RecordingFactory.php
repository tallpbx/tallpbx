<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Recordings\Models\Recording;

/**
 * @extends Factory<Recording>
 */
class RecordingFactory extends Factory
{
    protected $model = Recording::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Recording',
            'type' => fake()->randomElement(['moh', 'greeting', 'ivr', 'announcement']),
            'file_path' => 'recordings/'.fake()->uuid().'.wav',
            'duration' => fake()->numberBetween(5, 300),
            'enabled' => true,
        ];
    }
}
