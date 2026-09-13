<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Emergency\Models\Emergency;

/**
 * Generate fake Emergency (E911) records for testing.
 *
 * Creates emergency configs with random caller IDs, addresses, and coordinates.
 */
class EmergencyFactory extends Factory
{
    protected $model = Emergency::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'caller_id' => fake()->numerify('+1##########'),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
        ];
    }
}
