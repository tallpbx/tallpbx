<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Destinations\Models\Destination;

/**
 * @extends Factory<Destination>
 */
class DestinationFactory extends Factory
{
    protected $model = Destination::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
            'type' => fake()->randomElement(['conference', 'ivr', 'voicemail', 'ring_group', 'call_flow']),
            'dial_string' => (string) fake()->numberBetween(3000, 7999),
            'description' => fake()->sentence(),
            'enabled' => true,
        ];
    }

    /**
     * Scope the factory state to a specific tenant.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
