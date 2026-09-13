<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates tenant records for tests and seed data.
 *
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Return the default tenant attributes for factories.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'purpose' => Tenant::PURPOSE_CUSTOMER,
        ];
    }

    /**
     * Mark the tenant as the shared-resource default tenant.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Default',
            'slug' => 'default',
            'purpose' => Tenant::PURPOSE_DEFAULT,
        ]);
    }
}
