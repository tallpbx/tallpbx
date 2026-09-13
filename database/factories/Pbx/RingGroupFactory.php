<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\RingGroups\Models\RingGroup;

/**
 * Factory for generating ring group model instances in tests and seeding.
 *
 * Creates ring groups with a random strategy, timeout, and enabled state.
 * Attaches the ring-group to a tenant by default if tenant_id is omitted.
 */
class RingGroupFactory extends Factory
{
    /** @var class-string<RingGroup> */
    protected $model = RingGroup::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantFactory::new()->create(),
            'name' => fake()->unique()->words(2, true),
            'strategy' => fake()->randomElement(['ring-all', 'sequential', 'round-robin']),
            'ring_timeout' => fake()->numberBetween(15, 60),
            'description' => fake()->optional()->sentence(),
            'enabled' => true,
        ];
    }
}
