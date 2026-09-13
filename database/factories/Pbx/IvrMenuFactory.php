<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\IvrMenus\Models\IvrMenu;

/**
 * Factory for generating IVR menu instances in tests and seeders.
 *
 * @extends Factory<IvrMenu>
 */
class IvrMenuFactory extends Factory
{
    protected $model = IvrMenu::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word().' IVR',
            'greeting' => fake()->sentence(),
            'timeout' => fake()->randomElement([5, 10, 15, 20, 30]),
            'max_failures' => fake()->randomElement([3, 5, 10]),
            'digit_length' => fake()->randomElement([0, 1, 2, 3, 4]),
            'description' => fake()->optional()->sentence(),
            'enabled' => true,
        ];
    }

    /**
     * Set the tenant for this IVR menu.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
