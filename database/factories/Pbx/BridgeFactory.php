<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Bridges\Models\Bridge;

/**
 * Factory for generating bridge instances in tests and seeders.
 *
 * @extends Factory<Bridge>
 */
class BridgeFactory extends Factory
{
    protected $model = Bridge::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'bridge_name' => fake()->unique()->word().' Bridge',
            'destination_number' => fake()->numerify('####'),
            'pin_number' => fake()->optional()->numerify('####'),
            'description' => fake()->optional()->sentence(),
            'enabled' => true,
        ];
    }

    /**
     * Set the tenant for this bridge.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
