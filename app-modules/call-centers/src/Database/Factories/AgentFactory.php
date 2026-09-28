<?php

declare(strict_types=1);

namespace Modules\CallCenters\Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallCenters\Models\Agent;

/**
 * Generate fake CallCenter Agent records for testing.
 *
 * Creates call center agents with random names, types, and destinations.
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'type' => 'callback',
            'destination' => fake()->numerify('1##########'),
            'enabled' => true,
        ];
    }
}
