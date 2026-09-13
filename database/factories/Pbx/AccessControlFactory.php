<?php

declare(strict_types=1);

namespace Database\Factories\Pbx;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AccessControls\Models\AccessControl;
use Modules\AccessControls\Models\AccessControlNode;

/**
 * @extends Factory<AccessControl>
 */
class AccessControlFactory extends Factory
{
    protected $model = AccessControl::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
            'description' => fake()->sentence(),
            'action' => fake()->randomElement(['allow', 'deny']),
            'enabled' => true,
        ];
    }

    /**
     * Perform the withNodes operation.
     */
    public function withNodes(int $count = 1): static
    {
        return $this->has(
            AccessControlNode::factory()->count($count),
            'nodes',
        );
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
