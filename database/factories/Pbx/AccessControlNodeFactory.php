<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Acl\Models\AccessControlNode;

/**
 * @extends Factory<AccessControlNode>
 */
class AccessControlNodeFactory extends Factory
{
    protected $model = AccessControlNode::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['cidr', 'ip', 'domain']),
            'value' => fake()->ipv4(),
            'order' => fake()->numberBetween(0, 100),
        ];
    }
}
