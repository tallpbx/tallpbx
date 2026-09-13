<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FollowMe\Models\FollowMe;

/**
 * @extends Factory<FollowMe>
 */
class FollowMeFactory extends Factory
{
    protected $model = FollowMe::class;

    /**
     * Define the model's default state.
     *
     * Creates a follow-me record with a random name, a random
     * extension, and a standard ring timeout of 30 seconds.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' Forwarding',
            'extension' => (string) fake()->unique()->numberBetween(1000, 9999),
            'destination' => '+1'.fake()->numerify('555#######'),
            'ring_timeout' => 30,
            'enabled' => true,
        ];
    }
}
