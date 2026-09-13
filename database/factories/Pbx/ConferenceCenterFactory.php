<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConferenceCenters\Models\ConferenceCenter;

/**
 * @extends Factory<ConferenceCenter>
 */
class ConferenceCenterFactory extends Factory
{
    protected $model = ConferenceCenter::class;

    /**
     * Define the model's default state.
     *
     * Creates a conference center with a random name, a generated
     * extension number, and an optional PIN.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Conference Center',
            'extension' => fake()->unique()->numerify('5###'),
            'pin' => fake()->optional(0.8)->numerify('####'),
            'greeting' => null,
            'enabled' => true,
        ];
    }
}
