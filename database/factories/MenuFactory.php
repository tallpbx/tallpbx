<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    public function definition(): array
    {
        return [
            'module_name' => fake()->word(),
            'key' => fake()->unique()->word().'.'.fake()->word(),
            'label' => fake()->words(2, true),
            'route' => fake()->optional()->word().'.index',
            'icon' => 'heroicon-o-'.fake()->word(),
            'guard' => fake()->randomElement(['admin', 'web']),
            'order' => fake()->numberBetween(0, 100),
            'permission' => null,
            'enabled' => true,
        ];
    }
}
