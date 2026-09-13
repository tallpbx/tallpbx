<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\OutboundRoutes\Models\OutboundRoute;

/**
 * Generate fake OutboundRoute records for testing.
 *
 * Creates outbound call routing rules with random gateway assignments and patterns.
 */
class OutboundRouteFactory extends Factory
{
    protected $model = OutboundRoute::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->word().' Outbound',
            'dial_pattern' => '^('.$this->faker->numerify('#########').')$',
            'gateway' => null,
            'caller_id_name' => null,
            'caller_id_number' => null,
            'priority' => $this->faker->numberBetween(1, 200),
            'enabled' => true,
        ];
    }
}
