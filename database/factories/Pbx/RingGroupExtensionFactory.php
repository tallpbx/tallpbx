<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\RingGroups\Models\RingGroupExtension;

/**
 * Factory for generating ring group extension records in tests.
 *
 * Creates an extension entry for a ring group with a random
 * extension UUID and auto-incrementing position.
 */
class RingGroupExtensionFactory extends Factory
{
    /** @var class-string<RingGroupExtension> */
    protected $model = RingGroupExtension::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ring_group_id' => RingGroupFactory::new()->create(),
            'extension_uuid' => (string) fake()->unique()->numerify('2###'),
            'position' => fake()->numberBetween(1, 10),
        ];
    }
}
