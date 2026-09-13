<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\InboundRoutes\Models\InboundRoute;

/**
 * Generate fake InboundRoute records for testing.
 *
 * Creates inbound call routing rules with random DID numbers and destinations.
 */
class InboundRouteFactory extends Factory
{
    protected $model = InboundRoute::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->word().' Inbound',
            'destination_number' => '+1'.$this->faker->numerify('##########'),
            'action' => 'transfer',
            'action_data' => $this->faker->numerify('1###'),
            'priority' => $this->faker->numberBetween(1, 200),
            'enabled' => true,
        ];
    }
}
