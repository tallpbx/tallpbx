<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CallCenters\Models\Agent;

/**
 * Generate fake CallCenter Agent records for testing.
 *
 * Creates call center agents with random names, types, and destinations.
 */
class CallCenterAgentFactory extends Factory
{
    protected $model = Agent::class;

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
