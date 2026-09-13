<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('permission_####').'.'.fake()->randomElement(['view', 'create', 'update', 'delete']),
            'module' => fake()->word(),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Create a permission for a specific module.
     */
    public function forModule(string $module): static
    {
        return $this->state(fn (array $attributes) => [
            'module' => $module,
        ]);
    }
}
